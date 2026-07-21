<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
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
 * Streams subjects lazily and collects garbage per version (128 MB budget).
 *
 * Routing: ActiveReconsent → ReconsentRequired, InfoPush → LegalChangeInformational,
 * DeemedConsent → DeemedConsentNotice. A voluntary consent is never swept (Art. 7(4)).
 *
 * Delivery is deliberately AT-LEAST-ONCE: the `notified_at` watermark is stamped only after a
 * version's full subject sweep completes, so a clean re-run never re-sends, but a process killed
 * mid-sweep re-notifies rather than silently dropping a legally required notice. A duplicate
 * email is tolerable; a missed § 308 / § 675g notice is not.
 */
final class DispatchDueLegalNoticesCommand extends Command
{
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

            $resolver->forVersion($version)->each(function (Model $subject) use ($version, $notification, $proof, $tenant, $tokens, &$notified): void {
                // Pin the delivery locale to the version's: the notice is QUEUED, so without this
                // it renders in whatever locale the worker happens to run under — and the proof row
                // (rendered in the version's locale) would then certify text the subject never
                // received. Proof and delivery must be the same artifact.
                Notification::locale($version->locale)->send($subject, $notification);

                if ($proof !== null) {
                    // Pin the version's tenant around the proof write: this sweep crosses tenants
                    // and runs with no authenticated user, so an unpinned subject-token lookup
                    // would read the shared '' bucket, miss the subject's pseudonym and mint a
                    // fresh one — severing the notice proof from their consent ledger.
                    $tenant->forTenant($version->tenant_id, fn (): LegalNotice => $this->writeProof($subject, $version, $proof, $tokens));
                }

                $notified++;
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
    private function writeProof(Model $subject, LegalDocument $version, array $proof, SubjectToken $tokens): LegalNotice
    {
        return LegalNotice::query()->forceCreate([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            // The stable pseudonym, shared with this subject's consent ledger — it is what keeps
            // the proof tied to them once an Art. 17 erasure nulls subject_type/subject_id.
            'subject_token' => $tokens->forSubject($subject),
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
