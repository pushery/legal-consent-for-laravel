<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\LazyCollection;
use Pushery\LegalConsent\Console\Concerns\SkipsWhenTablesAreMissing;
use Pushery\LegalConsent\Contracts\LegalConsentMonitor;
use Pushery\LegalConsent\Contracts\SendsNoticeMail;
use Pushery\LegalConsent\Enums\DocumentType;
use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Events\NoticeDispatched;
use Pushery\LegalConsent\Events\NoticeDispatching;
use Pushery\LegalConsent\LegalConsentServiceProvider;
use Pushery\LegalConsent\Listeners\ReopenVersionOnNoticeFailure;
use Pushery\LegalConsent\Listeners\WriteNoticeDeliveryProof;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Models\LegalNotice;
use Pushery\LegalConsent\Models\Scopes\TenantScope;
use Pushery\LegalConsent\Notifications\ChangeNotification;
use Pushery\LegalConsent\Support\AffectedSubjectResolver;
use Pushery\LegalConsent\Support\IntegerSetting;
use Pushery\LegalConsent\Support\NoticeAttempts;
use Pushery\LegalConsent\Support\NoticeMailConfig;
use Pushery\LegalConsent\Support\ReportedNotificationFailures;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Version-level sweep: for every active version that owes a notice (active re-consent,
 * info-only, or deemed consent) whose announcement date has passed but which has not yet been
 * notified, hand the affected subjects the notification that matches its NoticeMode. Streams
 * subjects lazily in batches and collects garbage per version, so peak memory does not grow with
 * the size of the audience: each subject's notice is handed over with its own send() call.
 *
 * Routing: ActiveReconsent → ReconsentRequired, InfoPush → LegalChangeInformational,
 * DeemedConsent → DeemedConsentNotice. A voluntary consent is never swept (Art. 7(4)).
 *
 * A deemed-consent notice names the objection deadline, and § 308 Nr. 5 lit. b BGB wants its
 * warning at the start of the period to object. Once that deadline has passed, the notice can found
 * no acceptance and would tell its reader about a chance that is gone, so it is not sent: the
 * subjects it did not reach need a new version with a new deadline, and the run says so until one
 * replaces it.
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
 * § 675g notice is never risked. A process killed mid-sweep — or a run overtaken when its 55-minute
 * `withoutOverlapping` lock expires on a large population — RESUMES: with durable-medium proof on,
 * {@see AffectedSubjectResolver::forVersion()} skips subjects that already carry a proof row for the
 * version, and those whose notice is still on its way ({@see NoticeAttempts}), so the retry serves
 * only those still owed a notice and does not write a second proof for one already notified. This
 * removes the bulk of duplication without a unique constraint; a proof written by a genuinely
 * simultaneous sweep in the same window is still tolerated (a duplicate email is acceptable, a
 * missed notice is not). A notice that FAILS is counted against its subject and brings the version
 * back while the subject has attempts left — see {@see ReopenVersionOnNoticeFailure}. On a
 * synchronous queue that failure surfaces inside this run, and it ends the subject's notice on
 * that channel, not the run ({@see self::sendTo()}). A subject that has no attempts left is
 * reported on every run, and the run exits non-zero, because its notice is still owed.
 */
#[AsCommand(name: 'legal-consent:dispatch-notices')]
final class DispatchDueLegalNoticesCommand extends Command implements Isolatable
{
    use SkipsWhenTablesAreMissing;

    /** Subjects per batch — one attempts record and one token lookup per ledger per chunk. */
    private const int CHUNK = 500;

    protected $signature = 'legal-consent:dispatch-notices
        {--dry-run : Report the audience of every due version without sending, writing a proof row, or stamping the watermark}
        {--force : Send even where the audience exceeds notifications.max_recipients_per_run}';

    protected $description = 'Notify subjects of a now-announced legal change with the notification matching its notice mode.';

    /**
     * The minutes a run holds its lock: the scheduler's overlap lock and the `--isolated` lock alike,
     * inside the hour this sweep runs on, so a run that dies holding one does not cost the next sweep.
     */
    public const int LOCK_MINUTES = 55;

    /**
     * How long the `--isolated` lock is held, which every scheduled run takes too ({@see LegalConsentServiceProvider}).
     */
    public function isolationLockExpiresAt(): CarbonInterval
    {
        return CarbonInterval::minutes(self::LOCK_MINUTES);
    }

    public function handle(AffectedSubjectResolver $resolver, LegalConsentMonitor $monitor, ReportedNotificationFailures $reported): int
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
        $canceled = 0;
        $deficient = 0;
        $expired = 0;

        foreach ($versions as $version) {
            // Count BEFORE sending, always — not only when a limit is configured. The size of a
            // send is the one number an operator can never recover afterwards, and the aggregate
            // line at the end cannot say which version it belonged to. It counts whom this run will
            // send to, the same subjects the stream below serves: a version re-opened for a handful
            // of failed notices is not held back because its whole audience exceeds the brake.
            $audience = $resolver->countForVersion($version, skipNotified: $proofEnabled);

            $this->line(sprintf(
                '%s %s (%s): %d recipient(s).',
                $version->key,
                $version->version,
                $version->locale,
                $audience,
            ));

            $deadline = $this->passedDeadline($version);

            if ($deadline instanceof CarbonImmutable && $audience > 0) {
                // Skipped WITHOUT stamping, like the two hold-backs below, so every run names the
                // version until a new one replaces it.
                $expired++;
                $this->error(sprintf(
                    '  its objection deadline %s has passed, so a deemed-consent notice can no longer found an acceptance. Nothing was sent; the subjects it did not reach need a new version with a new objection deadline.',
                    $deadline->toIso8601String(),
                ));

                continue;
            }

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
                // required communication rather than defer it. Counted apart from the brake,
                // because the two are lifted in different places.
                $canceled++;
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
                ->each(function (LazyCollection $chunk) use ($notification, $version, $proofEnabled, $reported, &$notified): void {
                    // Positions from 0, so that the subjects from a stopping one on can be sliced off.
                    $subjects = $chunk->collect()->values();

                    // Recorded BEFORE the send: with a synchronous queue the notice is delivered or
                    // fails inside send(), and its outcome has to find the row already there. Only a
                    // notice that goes out by mail ever comes back as delivered or failed; one that
                    // does not would count as on its way until the window ends, and then be queued
                    // again as lost.
                    $queued = $proofEnabled ? NoticeAttempts::queued($version, $subjects->filter(
                        static fn (Model $subject): bool => in_array('mail', $notification->via($subject), true),
                    )->values()) : null;

                    // One send() per subject, and still one queued job per subject and channel. On
                    // a synchronous queue those jobs run inside send(), so one subject's refused
                    // notice has to stay that subject's (see sendTo()). The locale is already
                    // pinned on the notification itself, which matters because the notice is
                    // QUEUED: without a pin the worker would render it in whatever locale it
                    // happens to run under.
                    foreach ($subjects as $position => $subject) {
                        try {
                            $this->sendTo($subject, $notification, $reported);
                        } catch (Throwable $exception) {
                            // A failure Laravel did not report ends the run (see sendTo()). From
                            // this subject on no notice was handed over, so none of them may stand
                            // as on its way: the next run would pass them over until the window
                            // ends and then count an attempt nobody made. A notice of this subject
                            // that did go out, or failed, already changed its row and keeps it.
                            $queued?->putBack($subjects->slice($position));

                            throw $exception;
                        }
                    }

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

        if ($canceled > 0) {
            // Non-zero for the same reason, and named apart from the brake: a listener's hold is
            // lifted where the listener decides, and neither --force nor a higher limit reaches it.
            $this->error("{$canceled} version(s) held back by a NoticeDispatching listener. The notice is still owed and goes out on the first run the listener lets through; --force and notifications.max_recipients_per_run do not reach a listener.");
            $monitor->heartbeat('legal-consent:dispatch-notices.canceled', $canceled);
        }

        if ($expired > 0) {
            $this->error("{$expired} deemed-consent version(s) still owed a notice after their objection deadline had passed. Nothing was sent for them: silence cannot bind on a notice that arrives after the deadline it names, so publish a new version with a new objection deadline.");
            $monitor->heartbeat('legal-consent:dispatch-notices.expired', $expired);
        }

        $unreachable = $this->reportUnreachable();

        if ($unreachable > 0) {
            $monitor->heartbeat('legal-consent:dispatch-notices.unreachable', $unreachable);
        }

        return $held > 0 || $canceled > 0 || $deficient > 0 || $expired > 0 || $unreachable > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Names every version with subjects whose notice failed `notifications.max_attempts` times, and
     * returns how many subjects that is.
     *
     * Reported on every run, not only the one that gave up, because each of those notices is still
     * owed and nothing else in the schedule says so. The way out is to reach the subject: correct the
     * address and run `legal-consent:renotify`, which tries every subject of the version again, or
     * erase a subject that is gone.
     */
    private function reportUnreachable(): int
    {
        $unreachable = NoticeAttempts::unreachable();

        if ($unreachable === []) {
            return 0;
        }

        $versions = LegalDocument::model()::query()
            ->withoutGlobalScope(TenantScope::class) // the sweep crosses tenants
            ->whereKey(array_keys($unreachable))
            ->get();

        foreach ($unreachable as $documentId => $subjects) {
            $version = $versions->find($documentId);

            $this->error(sprintf(
                '  %s: %d subject(s) could not be reached after %d attempt(s), and their notice is still owed. Correct the address and run `legal-consent:renotify`, or erase a subject that is gone.',
                $version instanceof LegalDocument ? "{$version->key} {$version->version} ({$version->locale})" : "version #{$documentId}",
                $subjects,
                NoticeAttempts::maxAttempts(),
            ));
        }

        return array_sum($unreachable);
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
        return LegalNotice::model()::query()
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

        $limit = IntegerSetting::from(config('legal-consent.notifications.max_recipients_per_run'));

        return $limit !== null && $limit >= 0 && $audience > $limit;
    }

    /**
     * The versions this run owes a notice for.
     *
     * @return Collection<int, LegalDocument>
     */
    private function dueVersions(): Collection
    {
        return LegalDocument::model()::query()
            ->withoutGlobalScope(TenantScope::class) // sweep every tenant's due versions
            // The active version and the earlier ones of its major: a correction published after a
            // change does not cancel the notices that change still owes.
            ->ofTheActiveMajor()
            ->whereIn('notice_mode', [
                NoticeMode::ActiveReconsent->value,
                NoticeMode::InfoPush->value,
                NoticeMode::DeemedConsent->value,
            ])
            ->where('requires_explicit_optin', false) // mandatory docs only — never nag a voluntary consent (Art. 7(4))
            // An informational page binds nobody, so no change to it is owed a notice. The publisher
            // refuses every mode but the silent one for it, and this is the check that holds for a
            // row that came another way, a restored dump or a hand edit, as the gate and the banner do.
            ->where('type', '!=', DocumentType::Informational->value)
            ->whereNotNull('announce_from')
            ->where('announce_from', '<=', CarbonImmutable::now())
            // Not yet swept, or swept and owing a notice to try again: one that failed while the
            // sweep was still running, or one lost on its way.
            ->where(fn (Builder $query): Builder => NoticeAttempts::orWhereRetryDue($query->whereNull('notified_at')))
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
        $expired = 0;

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
            // WHAT THE SWAP COSTS, said rather than left to be discovered: `would be sent` now
            // counts an orphaned group too, and the real run cannot deliver to one. It is an
            // audience size, not a delivery forecast — it errs toward more notice rather than less,
            // which is the safe direction here, but it is not the same number.
            $remaining = $resolver->countForVersion($version, skipNotified: $proofEnabled);
            // The floor changes nothing observable: the resume count reads the same audience with one
            // more condition, so it never exceeds the total. It stays to say the difference cannot go
            // negative.
            $proofed = max(0, $total - $remaining);

            $deadline = $this->passedDeadline($version);

            if ($deadline instanceof CarbonImmutable && $remaining > 0) {
                $expired++;

                $this->line(sprintf(
                    '%s %s (%s, tenant %s): %d recipient(s), %d already proofed, queued or given up, 0 would be sent — its objection deadline %s has passed.',
                    $version->key,
                    $version->version,
                    $version->locale,
                    $version->tenant_id === '' ? '-' : $version->tenant_id,
                    $total,
                    $proofed,
                    $deadline->toIso8601String(),
                ));

                continue;
            }

            // The real sweep measures the brake against whom it will send to, after the resume
            // discount, so this has to as well or the two would disagree on the boundary.
            if ($this->exceedsLimit($remaining)) {
                $held++;

                $this->line(sprintf(
                    '%s %s (%s, tenant %s): %d recipient(s), %d already proofed, queued or given up, 0 would be sent — held back by notifications.max_recipients_per_run.',
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
                '%s %s (%s, tenant %s): %d recipient(s), %d already proofed, queued or given up, %d would be sent.',
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
        $this->line('NoticeDispatching listeners are not asked in a dry run, so a version one of them would hold back is counted here as one that would be sent.');

        if ($held > 0) {
            $this->error("{$held} version(s) would be held back by notifications.max_recipients_per_run. Re-run with --force or raise the limit.");
        }

        if ($expired > 0) {
            $this->error("{$expired} deemed-consent version(s) would not be sent: their objection deadline has passed. Publish a new version with a new objection deadline.");
        }

        // The subjects the real run gives up on are owed a notice whether or not anything is sent,
        // so the preview names them on the same exit code.
        $unreachable = $this->reportUnreachable();

        return $held > 0 || $expired > 0 || $unreachable > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** The objection deadline of a deemed-consent version, when it has already passed. */
    private function passedDeadline(LegalDocument $version): ?CarbonImmutable
    {
        $deadline = $version->objection_deadline;

        return $version->noticeMode() === NoticeMode::DeemedConsent
            && $deadline instanceof CarbonImmutable
            && $deadline->lessThanOrEqualTo(CarbonImmutable::now())
            ? $deadline
            : null;
    }

    /**
     * Hand one subject's notice over, and carry on past a channel Laravel reported as failed.
     *
     * On a synchronous queue the queued job runs inside `Notification::send()`. A channel that
     * throws there is reported as `NotificationFailed` and thrown on, by Laravel's notification
     * sender and then by the queue, so the exception reaches this loop. A worker would end that one
     * job and go on, and the sweep does the same: the failure is already counted against the
     * subject ({@see ReopenVersionOnNoticeFailure}), and the next subject is served. Without this,
     * the subjects after it in the chunk would be recorded as on their way and never sent, and the
     * version would keep its watermark clear.
     *
     * Only the exception Laravel reported is caught ({@see ReportedNotificationFailures}). Any
     * other one, from a queue that cannot be reached or a listener that throws, still ends the run.
     *
     * The queue takes a subject's channels one after another, so the channels after the refused one
     * were never handed over. They run now, inline, as the synchronous queue would have run them,
     * so a refused mail does not also cost the subject its notice on the other channels.
     */
    private function sendTo(Model $subject, ChangeNotification $notification, ReportedNotificationFailures $reported): void
    {
        try {
            Notification::send($subject, $notification);

            return;
        } catch (Throwable $exception) {
            $refused = $reported->channelOf($exception);

            if ($refused === null) {
                throw $exception;
            }
        }

        $channels = $notification->via($subject);
        $position = array_search($refused, $channels, true);

        foreach ($position === false ? [] : array_slice($channels, $position + 1) as $channel) {
            try {
                Notification::sendNow($subject, $notification, [$channel]);
            } catch (Throwable $exception) {
                if ($reported->channelOf($exception) === null) {
                    throw $exception;
                }
            }
        }
    }

    private function notificationFor(LegalDocument $version): ChangeNotification
    {
        // Routed through the config seam rather than matched here, so a consumer's subclass is used
        // by the sweep and by the PROOF rendering alike. Two different resolutions would be the
        // worst possible split: the subject would receive one text and the append-only row would
        // certify another.
        return NoticeMailConfig::notificationFor($version->noticeMode(), $version);
    }
}
