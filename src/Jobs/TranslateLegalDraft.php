<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Pushery\LegalConsent\Contracts\LegalTextTranslator;
use Pushery\LegalConsent\Exceptions\LegalDocumentTooLarge;
use Pushery\LegalConsent\Exceptions\LegalDocumentUnparsable;
use Pushery\LegalConsent\Exceptions\TranslatorNotConfigured;
use Pushery\LegalConsent\Models\LegalDraft;
use Pushery\LegalConsent\Support\LegalDraftSet;
use Pushery\LegalConsent\Support\LegalDraftWriter;

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
 * A dispatched job is invisible to the page that dispatched it. This writes a cache marker before it
 * starts and removes it when it ends — in `finally`, so a translator that throws does not leave a
 * screen claiming forever that a translation is running. The editor reads the marker to decide
 * whether to keep polling.
 *
 * ⚠️ IT IS A HINT, NOT A LOCK. Two operators translating the same draft at once is not a case this
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
    ) {}

    /** The cache key under which a translation of this draft reports itself as running. */
    public static function markerFor(string $key, string $locale): string
    {
        return "legal-consent:translating:{$key}:{$locale}";
    }

    public function handle(): void
    {
        $source = LegalDraftSet::for($this->key)->draft($this->sourceLocale);

        if (! $source instanceof LegalDraft) {
            // The source went away between dispatch and execution. Nothing to translate, and nothing
            // to report either: the editor already refuses this case in front of the reader.
            Cache::forget(self::markerFor($this->key, $this->locale));

            return;
        }

        try {
            $translated = app(LegalTextTranslator::class)->translate($source->body, $this->sourceLocale, $this->locale);

            app(LegalDraftWriter::class)->applyTranslation($this->key, $this->locale, $translated, $source->content_hash, $this->actor);
        } catch (TranslatorNotConfigured|LegalDocumentTooLarge|LegalDocumentUnparsable) {
            // The same three the inline path answers for, and they are swallowed here for a reason
            // rather than out of tidiness: on a queue there is nobody to tell. Re-raising would put
            // the job in `failed_jobs` — which is right for a defect and wrong for "your translator
            // is not configured", a state the editor reports in front of the reader on the next
            // render because no draft appeared.
        } finally {
            // In `finally`, so a throwing translator does not leave a screen polling forever for a
            // translation that already stopped.
            Cache::forget(self::markerFor($this->key, $this->locale));
        }
    }
}
