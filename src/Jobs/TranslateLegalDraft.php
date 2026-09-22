<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Jobs;

use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Pushery\LegalConsent\Contracts\LegalTextTranslator;
use Pushery\LegalConsent\Exceptions\LegalDocumentTooLarge;
use Pushery\LegalConsent\Exceptions\LegalDocumentUnparsable;
use Pushery\LegalConsent\Exceptions\TranslatorNotConfigured;
use Pushery\LegalConsent\Models\LegalDraft;
use Pushery\LegalConsent\Support\LegalDraftSet;
use Pushery\LegalConsent\Support\LegalDraftWriter;
use Throwable;

/**
 * A machine translation, off the request.
 *
 * ## Why this exists
 *
 * The editor called the translator inline. A consumer measured that ending in a 500 twice in one day
 * on a privacy notice — a document this package renders to about 15 kB, which is an ordinary length
 * for one and a long time for a language model. The request has a timeout; a translation does not
 * care about it.
 *
 * ## The translator is a SEAM, so its duration is not this package's to know
 *
 * `LegalTextTranslator` is bound by the application. It might be a local dictionary answering in
 * microseconds or a model answering in minutes, and nothing here can tell which. That is exactly why
 * the fix is not a longer timeout: a limit this package chose would be wrong in both directions.
 *
 * ## Opt-in, like everything else here that needs infrastructure
 *
 * `legal-consent.translation.queue`. Off, `translate()` behaves exactly as it did — which keeps an
 * application without a queue worker working, and makes the default the behavior that needs nothing.
 * On, the action returns immediately and this job does the work.
 *
 * ## The marker is what lets a screen say anything at all
 *
 * A dispatched job is invisible to the page that dispatched it. The editor writes a cache marker
 * before dispatching and this job moves it on, so the screen can say something rather than guess.
 * It carries THREE states, and the third is the one that was missing:
 *
 *  - `true` — running. The editor keeps polling.
 *  - a failure record — it ended, and it did not end well. The editor reads it once, says so, and
 *    removes it. An empty marker can only mean "not running"; it cannot be a sentence, and a
 *    sentence is what this package promises instead of an error page.
 *  - absent — nothing to report. A finished translation is evidenced by the draft itself.
 *
 * A `finally` DOES NOT COVER THE CASE THIS JOB EXISTS FOR. A worker killed at its timeout ends
 * inside a `SIGALRM` handler that calls `exit()`, so no `finally` in this file runs — and the marker
 * would stand for its full hour while the editor told the operator a translation was running that
 * died in the first minute. The same path covers `queue:restart` mid-job and an OOM kill. What still
 * fires there is Laravel's own failure route, so the answer is `failed()` rather than a block this
 * code hopes to reach.
 *
 * IT IS A HINT, NOT A LOCK. Two operators translating the same draft at once is not a case this
 * prevents, and it never pretended to: `applyTranslation()` owns that question, and the one that
 * lands second wins there as it always did. A marker that claimed to be a lock would be the more
 * dangerous thing, because a cache that evicts it would then silently unlock.
 */
final class TranslateLegalDraft implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly string $key,
        private readonly string $locale,
        private readonly string $sourceLocale,
        private readonly ?string $actor = null,
        private readonly ?string $guard = null,
    ) {
        // THE LANE IS SET HERE, on the properties the dispatcher reads.
        //
        // NOT `viaConnection()` / `viaQueue()`, which is the shape a queued notification or
        // listener uses and which nothing in `Illuminate\Bus` or `Illuminate\Queue` consults for a
        // job. Measured before relying on it: zero references in either namespace. Declared on a job
        // they are simply never called, which looks like configuration and is a method nobody runs.
        //
        // Read from configuration rather than taken at the dispatch site, so an application states
        // its lane once instead of at every caller — and so no caller can state a different one.
        $this->connection = $this->lane('legal-consent.translation.connection');
        $this->queue = $this->lane('legal-consent.translation.queue_name');
    }

    /**
     * A configured lane name, or null for the default.
     *
     * An empty string counts as "not set", because that is what an env-backed key resolves to when
     * the variable exists and is blank — a far more common shape in a published config than a
     * literal null. Dispatching onto a connection named `''` is not the same as the default, and the
     * difference does not show up anywhere until a job goes missing.
     */
    private function lane(string $key): ?string
    {
        $value = Config::get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** The cache key under which a translation of this draft reports itself as running. */
    public static function markerFor(string $key, string $locale): string
    {
        return "legal-consent:translating:{$key}:{$locale}";
    }

    /**
     * Record that a translation ended badly, for the screen to read once.
     *
     * The reason is the sentence the inline path would have shown, and only for the refusals whose
     * message is written FOR an operator. An unexpected defect passes none: an internal exception
     * message on an admin screen says nothing to the person reading it and can carry more than it
     * should.
     */
    public static function markFailed(string $key, string $locale, string $reason = ''): void
    {
        Cache::put(self::markerFor($key, $locale), ['failed' => $reason], now()->addHour());
    }

    /**
     * The failure reason if the last run left one, removing it as it reads.
     *
     * Null means no failure is recorded; an empty string means it failed without a sentence to pass
     * on. Read once, because a failure already spoken is not news on the next render — the TTL is
     * only there for the reader who never comes back.
     */
    public static function takeFailure(string $key, string $locale): ?string
    {
        $marker = Cache::get(self::markerFor($key, $locale));

        if (! is_array($marker) || ! array_key_exists('failed', $marker)) {
            return null;
        }

        Cache::forget(self::markerFor($key, $locale));

        return is_string($marker['failed']) ? $marker['failed'] : '';
    }

    public function handle(): void
    {
        $source = LegalDraftSet::for($this->key)->draft($this->sourceLocale);

        if (! $source instanceof LegalDraft) {
            // The source went away between dispatch and execution. Nothing to translate — and the
            // screen is still waiting, so it is told the run ended rather than left to notice that
            // no text ever arrived. No sentence: the source may well have been discarded on purpose.
            self::markFailed($this->key, $this->locale);

            return;
        }

        try {
            $translated = $this->asTheActor(
                fn (): string => app(LegalTextTranslator::class)->translate($source->body, $this->sourceLocale, $this->locale),
            );

            app(LegalDraftWriter::class)->applyTranslation($this->key, $this->locale, $translated, $source->content_hash, $this->actor);
        } catch (TranslatorNotConfigured|LegalDocumentTooLarge|LegalDocumentUnparsable $e) {
            // The same three the inline path answers for, and they are not re-raised here for a
            // reason rather than out of tidiness: putting "your translator is not configured" in
            // `failed_jobs` is right for a defect and wrong for a state somebody can fix on the
            // screen they are already looking at. Their message travels to that screen instead.
            self::markFailed($this->key, $this->locale, $e->getMessage());

            return;
        }

        // Only now, and NOT in a `finally`: a failure recorded above is the thing the screen has
        // left to read, and a forget here would wipe it. Anything else that throws leaves the marker
        // alone on purpose — the job is retried or it fails, and `failed()` is what answers then.
        Cache::forget(self::markerFor($this->key, $this->locale));
    }

    /**
     * Run the translator as the person who asked for the translation.
     *
     * A worker has nobody signed in. A translator that bills or limits per user reads the acting
     * user the way it would on a request, and on a worker it read nobody: the translation ran
     * unattributed and past any per-user limit, and nothing reported it. So the job carries the
     * identifier and the guard the editor read them from, signs that user in on that guard for the
     * translator call alone, and puts the worker back the way it found it afterwards, because a
     * worker process outlives the job.
     *
     * A user who is gone by the time the worker runs is not an error: the translation runs as it
     * did before, unattributed, rather than being refused. A job queued by a release that did not
     * carry a guard yet runs the same way.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $call
     * @return TResult
     */
    private function asTheActor(Closure $call): mixed
    {
        // A job serialized before the guard was carried has no value for it at all, and reading an
        // uninitialized property throws. `??` answers for both that and null, as isset() does.
        $guardName = $this->guard ?? null;

        if ($this->actor === null || $guardName === null) {
            return $call();
        }

        $auth = app(AuthManager::class);
        $provider = Config::get("auth.guards.{$guardName}.provider");
        $user = is_string($provider) ? $auth->createUserProvider($provider)?->retrieveById($this->actor) : null;

        if (! $user instanceof Authenticatable) {
            return $call();
        }

        $previousDefault = $auth->getDefaultDriver();
        $guard = $auth->guard($guardName);
        $previousUser = $guard->hasUser() ? $guard->user() : null;

        $guard->setUser($user);
        $auth->shouldUse($guardName);

        try {
            return $call();
        } finally {
            $auth->shouldUse($previousDefault);

            if ($previousUser instanceof Authenticatable) {
                $guard->setUser($previousUser);
            } elseif (method_exists($guard, 'forgetUser')) {
                $guard->forgetUser();
            }
        }
    }

    /**
     * The only return path a worker killed at its timeout still takes.
     *
     * `Worker::registerTimeoutHandler()` installs a `SIGALRM` handler that ends in `kill()`, which
     * calls `exit()` — the process stops inside the handler, so nothing in `handle()` runs to its
     * end, `finally` included. Laravel's failure route is what still fires, and it arrives here.
     *
     * Without it the marker stands for its full hour: the editor keeps polling and keeps telling the
     * operator that a translation is running, over a worker that died in the first minute. Nothing
     * is on the screen, nothing is in a log they read. The same path covers `queue:restart` during a
     * job and an OOM kill.
     *
     * No reason travels with it. The throwable here describes a defect or a limit the worker hit;
     * either is for whoever reads `failed_jobs`, not for the person waiting on a text.
     */
    public function failed(?Throwable $throwable): void
    {
        self::markFailed($this->key, $this->locale);
    }
}
