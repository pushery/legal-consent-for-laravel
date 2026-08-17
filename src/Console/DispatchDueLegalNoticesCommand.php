<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\LazyCollection;
use Pushery\LegalConsent\Contracts\LegalConsentMonitor;
use Pushery\LegalConsent\Contracts\SendsNoticeMail;
use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Events\NoticeDispatched;
use Pushery\LegalConsent\Events\NoticeDispatching;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Models\LegalNotice;
use Pushery\LegalConsent\Models\Scopes\TenantScope;
use Pushery\LegalConsent\Support\AffectedSubjectResolver;
use Pushery\LegalConsent\Support\NoticeMailConfig;
use Pushery\LegalConsent\Support\SubjectToken;
use Pushery\LegalConsent\Support\TenantContext;

/**
 * Version-level sweep: for every active version that owes a notice (active re-consent,
 * info-only, or deemed consent) whose announcement date has passed but which has not yet been
 * notified, notify the affected subjects with the notification that matches its NoticeMode and
 * — when durable-medium proof is on — write an append-only legal_notices proof row per subject.
 * Streams subjects lazily in batches and collects garbage per version (128 MB budget): each chunk
 * is notified with one send() call and — the actual round-trip saving — resolves its pseudonym tokens
 * in one query per ledger instead of two per subject. The queued notification jobs and the proof-row
 * inserts stay one per subject, as they inherently must.
 *
 * Routing: ActiveReconsent → ReconsentRequired, InfoPush → LegalChangeInformational,
 * DeemedConsent → DeemedConsentNotice. A voluntary consent is never swept (Art. 7(4)).
 *
 * Delivery is deliberately AT-LEAST-ONCE: the `notified_at` watermark is stamped only after a
 * version's full subject sweep completes, so a clean re-run never re-sends, and a missed § 308 /
 * § 675g notice is never risked. A process killed mid-sweep — or a run overtaken when the 120-min
 * `withoutOverlapping` lock expires on a large population — RESUMES: with durable-medium proof on,
 * {@see AffectedSubjectResolver::forVersion()} skips subjects that already carry a proof row for the
 * version, so the retry serves only those still owed a notice and does not write a second proof for
 * one already notified. This removes the bulk of duplication without a unique constraint; a proof
 * written by a genuinely simultaneous sweep in the same window is still tolerated (a duplicate email
 * is acceptable, a missed notice is not).
 */
final class DispatchDueLegalNoticesCommand extends Command
{
    /** Subjects processed per batch — one send() call and one token lookup per ledger per chunk. */
    private const int CHUNK = 500;

    protected $signature = 'legal-consent:dispatch-notices
        {--dry-run : Report the audience of every due version without sending, writing a proof row, or stamping the watermark}
        {--force : Send even where the audience exceeds notifications.max_recipients_per_run}';

    protected $description = 'Notify subjects of a now-announced legal change with the notification matching its notice mode.';

    public function handle(AffectedSubjectResolver $resolver, LegalConsentMonitor $monitor, TenantContext $tenant, SubjectToken $tokens): int
    {
        DB::disableQueryLog();

        $proofEnabled = (bool) config('legal-consent.durable_medium.proof', true);
        $medium = $this->medium();

        if ($this->option('dry-run')) {
            return $this->report($resolver);
        }

        $versions = $this->dueVersions();

        $notified = 0;
        $held = 0;

        foreach ($versions as $version) {
            // Count BEFORE sending, always — not only when a limit is configured. The size of a
            // send is the one number an operator can never recover afterwards, and the aggregate
            // line at the end cannot say which version it belonged to.
            $audience = $resolver->countForVersion($version);

            $this->line(sprintf(
                '%s %s (%s): %d recipient(s).',
                $version->key,
                $version->version,
                $version->locale,
                $audience,
            ));

            if ($this->exceedsLimit($audience)) {
                // Skip WITHOUT stamping. The version stays due, so nothing is lost and the next run
                // — or the same run with --force — still owes exactly this notice. Stamping here
                // would repeat the defect this brake exists because of.
                $held++;
                $this->warn('  held back: more than the configured notifications.max_recipients_per_run. Nothing was sent and the watermark is untouched.');

                continue;
            }

            // event($object), not the Dispatchable static: the static builds a NEW instance from
            // its arguments, so the $cancel a listener sets would land on an object nobody reads.
            $dispatching = new NoticeDispatching($version, $audience);
            event($dispatching);

            if ($dispatching->cancel) {
                // Held back WITHOUT stamping, exactly like the size brake: the notice stays owed
                // and the next sweep picks it up. A cancel that stamped would waive a legally
                // required communication rather than defer it.
                $held++;
                $this->warn('  held back: a NoticeDispatching listener canceled this version. Nothing was sent and the watermark is untouched.');

                continue;
            }

            $notification = $this->notificationFor($version);

            // Pin the locale on the NOTIFICATION, never through Notification::locale(). The facade
            // stores its locale on the ChannelManager, which memoizes a NotificationSender with
            // `??=` — the sender therefore keeps whatever locale the FIRST send of the process set,
            // and queueNotification() then overwrites every notification's own locale with that
            // frozen value. This sweep notifies several versions, each in its own language, in one
            // process: through the facade, every version after the first would render in the first
            // one's language while renderProof() — which sets the locale on the application — went
            // on certifying the right one. The proof row is append-only, so that mismatch would be
            // an uncorrectable record of a text the subject never received.
            $notification->locale($version->locale);

            $before = $notified;

            $proof = $proofEnabled ? $this->renderProof($notification, $version, $medium) : null;

            // RESUMABLE + BATCHED: skip subjects already proofed for this version (a killed or
            // lock-expired run resumes on those still owed a notice), and process in chunks so the
            // subject-token lookup is one query per ledger instead of two per subject (the queued
            // notification jobs and the proof-row inserts stay one per subject). `skipNotified` only
            // bites when proof is on; with proof off there is nothing to resume from and no proof to
            // duplicate — a killed run then re-notifies the WHOLE population (tolerated duplicate
            // emails under at-least-once), it simply writes no duplicate proof row.
            $resolver->forVersion($version, skipNotified: $proofEnabled)
                ->chunk(self::CHUNK)
                ->each(function (LazyCollection $chunk) use ($version, $notification, $proof, $tenant, $tokens, &$notified): void {
                    $subjects = $chunk->collect();

                    // One send() for the whole chunk — still one queued job per subject (the win is
                    // the batched token lookup below). The locale is already pinned on the
                    // notification itself, which matters because the notice is QUEUED: without a
                    // pin the worker would render it in whatever locale it happens to run under.
                    Notification::send($subjects, $notification);

                    if ($proof !== null) {
                        // Pin the version's tenant around the token lookup + proof writes: this sweep
                        // crosses tenants and runs unauthenticated, so an unpinned lookup would read
                        // the shared '' bucket, miss the subject's pseudonym and mint a fresh one —
                        // severing the notice proof from their consent ledger. Resolve the whole
                        // chunk's tokens in one query per ledger, then write each proof with its token.
                        $tenant->forTenant($version->tenant_id, function () use ($subjects, $version, $proof, $tokens): void {
                            $tokenMap = $tokens->forSubjects($subjects);

                            foreach ($subjects as $subject) {
                                $this->writeProof($subject, $version, $proof, $tokenMap[$tokens->mapKey($subject)]);
                            }
                        });
                    }

                    $notified += $subjects->count();
                });

            $version->forceFill(['notified_at' => CarbonImmutable::now()])->saveQuietly();

            // The only signal that says a legally required communication went out, and how far it
            // reached. A sweep that quietly reaches nobody is the defect this package has already
            // paid for once; a metric on this event makes it visible without reading a log.
            event(new NoticeDispatched($version, $notified - $before, $proof === null ? 0 : $notified - $before));

            gc_collect_cycles();
        }

        $monitor->heartbeat('legal-consent:dispatch-notices', $notified);

        $this->info("Dispatched {$notified} notice(s) across {$versions->count()} version(s).");

        if ($held > 0) {
            // Non-zero, because a held notice is still owed. A scheduled run that reports success
            // while a legally required notice sits undelivered is the shape of failure this whole
            // release is about.
            $this->error("{$held} version(s) held back by notifications.max_recipients_per_run. Review with --dry-run, then release with --force or raise the limit.");
            $monitor->heartbeat('legal-consent:dispatch-notices.held', $held);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Is this audience larger than the operator's configured brake?
     *
     * Unset (the default) means no brake at all — see the config comment. `--force` is the escape
     * for the run where they have looked at the number and decided.
     */
    private function exceedsLimit(int $audience): bool
    {
        if ($this->option('force')) {
            return false;
        }

        $limit = config('legal-consent.notifications.max_recipients_per_run');

        return is_int($limit) && $limit >= 0 && $audience > $limit;
    }

    /**
     * The versions this run owes a notice for.
     *
     * @return Collection<int, LegalDocument>
     */
    private function dueVersions(): Collection
    {
        return LegalDocument::query()
            ->withoutGlobalScope(TenantScope::class) // sweep every tenant's due versions
            ->where('is_active', true)
            ->whereIn('notice_mode', [
                NoticeMode::ActiveReconsent->value,
                NoticeMode::InfoPush->value,
                NoticeMode::DeemedConsent->value,
            ])
            ->where('requires_explicit_optin', false) // mandatory docs only — never nag a voluntary consent (Art. 7(4))
            ->whereNotNull('announce_from')
            ->where('announce_from', '<=', CarbonImmutable::now())
            ->whereNull('notified_at')
            ->get();
    }

    /**
     * Report what a real run would send, and change nothing.
     *
     * The non-gating modes reached nobody until the audience became mode-dependent, so the first
     * real sweep after that fix is also the first time an operator's info-only changes actually
     * leave the queue — for a large installation that is a fan-out they have never seen. This is
     * where they get to look at the number first.
     */
    private function report(AffectedSubjectResolver $resolver): int
    {
        $proofEnabled = (bool) config('legal-consent.durable_medium.proof', true);

        foreach ($this->dueVersions() as $version) {
            $total = $resolver->countForVersion($version);
            // What a real run would actually send: the resume predicate skips subjects already
            // proofed for this version, so reporting the raw total would overstate a resumed run.
            $remaining = $resolver->forVersion($version, skipNotified: $proofEnabled)->count();

            $this->line(sprintf(
                '%s %s (%s, tenant %s): %d recipient(s), %d already proofed, %d would be sent.',
                $version->key,
                $version->version,
                $version->locale,
                $version->tenant_id === '' ? '-' : $version->tenant_id,
                $total,
                max(0, $total - $remaining),
                $remaining,
            ));
        }

        $this->info('Dry run — nothing was sent, no proof was written, no watermark was stamped.');

        return self::SUCCESS;
    }

    private function notificationFor(LegalDocument $version): BaseNotification&SendsNoticeMail
    {
        // Routed through the config seam rather than matched here, so a consumer's subclass is used
        // by the sweep and by the PROOF rendering alike. Two different resolutions would be the
        // worst possible split: the subject would receive one text and the append-only row would
        // certify another.
        return NoticeMailConfig::notificationFor($version->noticeMode(), $version);
    }

    /**
     * Render the notice content once per version in the version's locale — the durable-medium
     * proof of what was communicated (the per-subject fact is the delivery row itself).
     *
     * @return array{body: string, hash: string, medium: string, mandatory_ok: bool}
     */
    private function renderProof(BaseNotification&SendsNoticeMail $notification, LegalDocument $version, string $medium): array
    {
        $original = app()->getLocale();
        app()->setLocale($version->locale);

        try {
            $mail = $notification->toMail($version);

            /** @var list<string> $lines */
            $lines = array_values(array_filter([
                $mail->subject,
                ...$mail->introLines,
                $mail->actionText,
                ...$mail->outroLines,
            ], is_string(...)));

            // Must be evaluated INSIDE the version's locale — the same locale the notice was
            // rendered in — or it would certify a translation the subject never received.
            // Ask the notice itself: a non-empty body proves nothing (subject + intro alone keep
            // it non-empty while the § 308 Nr. 5 lit. b warning silently drops out).
            $mandatoryOk = $notification->mandatoryContentPresent();
        } finally {
            app()->setLocale($original);
        }

        $body = implode("\n", $lines);

        return [
            'body' => $body,
            'hash' => hash('sha256', $body),
            'medium' => $medium,
            'mandatory_ok' => $mandatoryOk,
        ];
    }

    /**
     * @param  array{body: string, hash: string, medium: string, mandatory_ok: bool}  $proof
     */
    private function writeProof(Model $subject, LegalDocument $version, array $proof, string $subjectToken): LegalNotice
    {
        return LegalNotice::query()->forceCreate([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            // The stable pseudonym, shared with this subject's consent ledger — it is what keeps
            // the proof tied to them once an Art. 17 erasure nulls subject_type/subject_id. Resolved
            // once per chunk (see forSubjects), not per subject.
            'subject_token' => $subjectToken,
            // Carry the VERSION's tenant explicitly: this sweep crosses tenants and runs without
            // an authenticated user, so the ambient tenant would resolve to the shared '' bucket
            // and the proof would be invisible to the tenant it belongs to. An explicitly-set
            // value is honored (BelongsToTenant only stamps a null attribute).
            'tenant_id' => $version->tenant_id,
            'document_id' => $version->getKey(),
            'document_key' => $version->key,
            'document_version' => $version->version,
            'document_major_version' => $version->major_version,
            // The language the notice went out in — the notice_body is rendered under this
            // locale, so the proof states it rather than leaving it to be inferred.
            'locale' => $version->locale,
            'notice_mode' => $version->noticeMode(),
            'medium' => $proof['medium'],
            'notice_body' => $proof['body'],
            'notice_content_hash' => $proof['hash'],
            'mandatory_content_ok' => $proof['mandatory_ok'],
            'sent_at' => CarbonImmutable::now(),
        ]);
    }

    private function medium(): string
    {
        $channels = config('legal-consent.durable_medium.channels', ['mail']);
        $channels = is_array($channels) ? $channels : ['mail'];

        return in_array('mail', $channels, true) ? 'email' : 'durable_message';
    }
}
