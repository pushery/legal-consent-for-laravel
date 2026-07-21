<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\LazyCollection;
use Pushery\LegalConsent\Contracts\LegalConsentMonitor;
use Pushery\LegalConsent\Contracts\SendsNoticeMail;
use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Models\LegalNotice;
use Pushery\LegalConsent\Models\Scopes\TenantScope;
use Pushery\LegalConsent\Notifications\DeemedConsentNotice;
use Pushery\LegalConsent\Notifications\LegalChangeInformational;
use Pushery\LegalConsent\Notifications\ReconsentRequired;
use Pushery\LegalConsent\Support\AffectedSubjectResolver;
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

    protected $signature = 'legal-consent:dispatch-notices';

    protected $description = 'Notify subjects of a now-announced legal change with the notification matching its notice mode.';

    public function handle(AffectedSubjectResolver $resolver, LegalConsentMonitor $monitor, TenantContext $tenant, SubjectToken $tokens): int
    {
        DB::disableQueryLog();

        $proofEnabled = (bool) config('legal-consent.durable_medium.proof', true);
        $medium = $this->medium();

        $versions = LegalDocument::query()
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

        $notified = 0;

        foreach ($versions as $version) {
            $notification = $this->notificationFor($version);
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

                    // One locale-pinned send() for the whole chunk — still one queued job per subject
                    // (the win is the shared locale-pin plus the batched token lookup below). The
                    // locale pin matters because the notice is QUEUED: without it the worker would
                    // render it — and the proof row, rendered in the version's locale — in whatever
                    // locale it happens to run under, certifying text the subject never received.
                    Notification::locale($version->locale)->send($subjects, $notification);

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

            gc_collect_cycles();
        }

        $monitor->heartbeat('legal-consent:dispatch-notices', $notified);

        $this->info("Dispatched {$notified} notice(s) across {$versions->count()} version(s).");

        return self::SUCCESS;
    }

    private function notificationFor(LegalDocument $version): BaseNotification&SendsNoticeMail
    {
        return match ($version->noticeMode()) {
            NoticeMode::DeemedConsent => new DeemedConsentNotice($version),
            NoticeMode::ActiveReconsent => new ReconsentRequired($version),
            default => new LegalChangeInformational($version), // InfoPush
        };
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
            // value is honoured (BelongsToTenant only stamps a null attribute).
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
