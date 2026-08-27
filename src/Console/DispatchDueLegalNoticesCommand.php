<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\LazyCollection;
use Pushery\LegalConsent\Console\Concerns\SkipsWhenTablesAreMissing;
use Pushery\LegalConsent\Contracts\LegalConsentMonitor;
use Pushery\LegalConsent\Contracts\SendsNoticeMail;
use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Events\NoticeDispatched;
use Pushery\LegalConsent\Events\NoticeDispatching;
use Pushery\LegalConsent\Listeners\ReopenVersionOnNoticeFailure;
use Pushery\LegalConsent\Listeners\WriteNoticeDeliveryProof;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Models\LegalNotice;
use Pushery\LegalConsent\Models\Scopes\TenantScope;
use Pushery\LegalConsent\Support\AffectedSubjectResolver;
use Pushery\LegalConsent\Support\NoticeMailConfig;

/**
 * Version-level sweep: for every active version that owes a notice (active re-consent,
 * info-only, or deemed consent) whose announcement date has passed but which has not yet been
 * notified, hand the affected subjects the notification that matches its NoticeMode. Streams
 * subjects lazily in batches and collects garbage per version, so peak memory does not grow with
 * the size of the audience: each chunk is queued with one send() call.
 *
 * Routing: ActiveReconsent → ReconsentRequired, InfoPush → LegalChangeInformational,
 * DeemedConsent → DeemedConsentNotice. A voluntary consent is never swept (Art. 7(4)).
 *
 * THIS RUN QUEUES; IT DOES NOT DELIVER, and it no longer says otherwise. The notifications are
 * `ShouldQueue`, so `Notification::send()` returns once the jobs are on the queue. The
 * append-only durable-medium proof is therefore written by {@see WriteNoticeDeliveryProof} from
 * the `NotificationSent` event — a row states that a notice went out, and this command is in no
 * position to know that. Writing it here certified an enqueue, which came apart from delivery on
 * exactly the runs that matter: a dead worker or a refusing transport produced a green sweep, no
 * mail, and an uncorrectable row that `legal-consent:close-objection-windows` then reads to bind
 * a subject by silence.
 *
 * Delivery is deliberately AT-LEAST-ONCE: the `notified_at` watermark is stamped only after a
 * version's full subject sweep completes, so a clean re-run never re-queues, and a missed § 308 /
 * § 675g notice is never risked. A process killed mid-sweep — or a run overtaken when the 120-min
 * `withoutOverlapping` lock expires on a large population — RESUMES: with durable-medium proof on,
 * {@see AffectedSubjectResolver::forVersion()} skips subjects that already carry a proof row for the
 * version, so the retry serves only those still owed a notice and does not write a second proof for
 * one already notified. This removes the bulk of duplication without a unique constraint; a proof
 * written by a genuinely simultaneous sweep in the same window is still tolerated (a duplicate email
 * is acceptable, a missed notice is not). A channel that FAILS re-opens the version rather than
 * leaving it stamped — see {@see ReopenVersionOnNoticeFailure}.
 */
final class DispatchDueLegalNoticesCommand extends Command implements Isolatable
{
    use SkipsWhenTablesAreMissing;

    /** Subjects processed per batch — one send() call and one token lookup per ledger per chunk. */
    private const int CHUNK = 500;

    protected $signature = 'legal-consent:dispatch-notices
        {--dry-run : Report the audience of every due version without sending, writing a proof row, or stamping the watermark}
        {--force : Send even where the audience exceeds notifications.max_recipients_per_run}';

    protected $description = 'Notify subjects of a now-announced legal change with the notification matching its notice mode.';

    public function handle(AffectedSubjectResolver $resolver, LegalConsentMonitor $monitor): int
    {
        DB::disableQueryLog();

        if ($this->tablesAreMissing(['legal_documents', 'legal_consents', 'legal_notices'])) {
            return self::SUCCESS;
        }

        $proofEnabled = (bool) config('legal-consent.durable_medium.proof', true);

        if ($this->option('dry-run')) {
            return $this->report($resolver);
        }

        $versions = $this->dueVersions();

        $notified = 0;
        $held = 0;
        $deficient = 0;

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
            // one's language while the proof — which is rendered under the version's locale — went
            // on certifying the right one. The proof row is append-only, so that mismatch would be
            // an uncorrectable record of a text the subject never received.
            $notification->locale($version->locale);

            // Whether this notice carries the mandatory content its regime demands, asked ONCE per
            // version because the answer depends on the version and its locale, not on who
            // receives it. The proof row records the same fact per delivery; what was missing was
            // anyone READING it: a notice with an emptied § 308 Nr. 5 lit. b warning went out, was
            // logged as deficient, stamped the watermark and ended the run at exit 0.
            if (! $this->mandatoryContentPresent($notification, $version)) {
                $deficient++;
                $this->error(sprintf(
                    '  %s %s (%s) does not carry the mandatory content its notice mode requires — the notice goes out, but it cannot found a deemed acceptance. Fix the `legal-consent::notifications` lines for this locale, then re-run with `legal-consent:renotify`.',
                    $version->key,
                    $version->version,
                    $version->locale,
                ));
            }

            $before = $notified;

            // RESUMABLE: skip subjects already proved for this version, so a killed or
            // lock-expired run resumes on those still owed a notice. `skipNotified` only bites when
            // proof is on; with proof off there is nothing to resume from and no proof to
            // duplicate — a killed run then re-notifies the WHOLE population (tolerated duplicate
            // emails under at-least-once), it simply writes no duplicate proof row.
            $resolver->forVersion($version, skipNotified: $proofEnabled)
                ->chunk(self::CHUNK)
                ->each(function (LazyCollection $chunk) use ($notification, &$notified): void {
                    $subjects = $chunk->collect();

                    // One send() for the whole chunk — still one queued job per subject. The locale
                    // is already pinned on the notification itself, which matters because the
                    // notice is QUEUED: without a pin the worker would render it in whatever locale
                    // it happens to run under.
                    Notification::send($subjects, $notification);

                    $notified += $subjects->count();
                });

            $version->forceFill(['notified_at' => CarbonImmutable::now()])->saveQuietly();

            // The signal that says how far a legally required communication reached. A sweep that
            // quietly reaches nobody is the defect this package has already paid for once; a metric
            // on this event makes it visible without reading a log.
            event(new NoticeDispatched($version, $notified - $before, $this->provedFor($version)));

            gc_collect_cycles();
        }

        $monitor->heartbeat('legal-consent:dispatch-notices', $notified);

        // "Queued", not "sent": what this run can attest to is that the jobs are on the queue. The
        // durable-medium proof row is written when a notice actually leaves the mailer.
        $this->info("Queued {$notified} notice(s) for delivery across {$versions->count()} version(s).");

        if ($deficient > 0) {
            // Non-zero, and named. A notice missing its mandatory line is void where it matters
            // most — § 308 Nr. 5 lit. b makes the silence warning a validity condition — and until
            // now the only trace was a boolean column nothing in this command read.
            $this->error("{$deficient} version(s) went out without their mandatory notice content. Silence cannot bind against those notices; fix the wording and re-notify.");
            $monitor->heartbeat('legal-consent:dispatch-notices.deficient', $deficient);
        }

        if ($held > 0) {
            // Non-zero, because a held notice is still owed. A scheduled run that reports success
            // while a legally required notice sits undelivered is the shape of failure this whole
            // release is about.
            $this->error("{$held} version(s) held back by notifications.max_recipients_per_run. Review with --dry-run, then release with --force or raise the limit.");
            $monitor->heartbeat('legal-consent:dispatch-notices.held', $held);
        }

        return $held > 0 || $deficient > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Does this version's notice carry the mandatory content its regime demands?
     *
     * Evaluated INSIDE the version's locale — the same locale the notice is rendered and proved
     * in — or it would pass on a translation the subject never receives.
     */
    private function mandatoryContentPresent(BaseNotification&SendsNoticeMail $notification, LegalDocument $version): bool
    {
        $original = app()->getLocale();
        app()->setLocale($version->locale);

        try {
            return $notification->mandatoryContentPresent();
        } finally {
            app()->setLocale($original);
        }
    }

    /**
     * How many delivery proofs stand for this version once the sweep has queued its audience.
     *
     * With a synchronous queue that is the whole audience; with a worker it is whatever has been
     * delivered so far, which is usually nothing yet. Both are the honest number — the proof is
     * written by the delivery, not by this run.
     */
    private function provedFor(LegalDocument $version): int
    {
        return LegalNotice::query()
            ->withoutGlobalScope(TenantScope::class) // the sweep crosses tenants
            ->where('document_id', $version->getKey())
            ->count();
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
     *
     * It runs the SAME brake the real sweep runs, and ends on the same exit code. A preview that
     * promises a fan-out the real run refuses is worse than no preview — and three different texts
     * (the sweep's own error, `legal-consent:renotify`, the config comment) send an operator here
     * for exactly the case the brake decides.
     */
    private function report(AffectedSubjectResolver $resolver): int
    {
        $proofEnabled = (bool) config('legal-consent.durable_medium.proof', true);
        $held = 0;

        foreach ($this->dueVersions() as $version) {
            $total = $resolver->countForVersion($version);
            // The resume predicate skips subjects already proofed for this version, so reporting
            // the raw total would overstate a resumed run.
            //
            // COUNTED, NOT STREAMED, and the reason is correctness before cost. `forVersion()` is
            // the sweep's hydrate path: it loads real subject models a page at a time, and calling
            // `count()` on it built the entire remaining population as objects purely to arrive at
            // an integer, in a command whose whole promise is that it writes and sends nothing.
            //
            // The cost was the smaller half. The two paths answer the same question over the same
            // grouped set EXCEPT for an orphaned group — one whose `subject_type` no longer maps to
            // a live model class, because the app removed or renamed the model. The aggregate
            // counts it; the hydrate path silently drops it, having nothing to build. Subtracting
            // one from the other therefore booked every orphaned group as `already proofed`:
            // measured before this change, a ledger holding one live subject and one orphan
            // reported "2 recipient(s), 1 already proofed, 1 would be sent" with not one proof row
            // in the database. On the line an operator reads before sending a legally required
            // communication, a fabricated "already served" is the worst of the available errors.
            //
            // ⚠️ WHAT THE SWAP COSTS, said rather than left to be discovered: `would be sent` now
            // counts an orphaned group too, and the real run cannot deliver to one. It is an
            // audience size, not a delivery forecast — it errs toward more notice rather than less,
            // which is the safe direction here, but it is not the same number.
            $remaining = $resolver->countForVersion($version, skipNotified: $proofEnabled);
            $proofed = max(0, $total - $remaining);

            // The real sweep measures the brake against the RAW audience, before the resume
            // discount, so this has to as well or the two would disagree on the boundary.
            if ($this->exceedsLimit($total)) {
                $held++;

                $this->line(sprintf(
                    '%s %s (%s, tenant %s): %d recipient(s), %d already proofed, 0 would be sent — held back by notifications.max_recipients_per_run.',
                    $version->key,
                    $version->version,
                    $version->locale,
                    $version->tenant_id === '' ? '-' : $version->tenant_id,
                    $total,
                    $proofed,
                ));

                continue;
            }

            $this->line(sprintf(
                '%s %s (%s, tenant %s): %d recipient(s), %d already proofed, %d would be sent.',
                $version->key,
                $version->version,
                $version->locale,
                $version->tenant_id === '' ? '-' : $version->tenant_id,
                $total,
                $proofed,
                $remaining,
            ));
        }

        $this->info('Dry run — nothing was sent, no proof was written, no watermark was stamped.');

        if ($held > 0) {
            $this->error("{$held} version(s) would be held back by notifications.max_recipients_per_run. Re-run with --force or raise the limit.");

            return self::FAILURE;
        }

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
}
