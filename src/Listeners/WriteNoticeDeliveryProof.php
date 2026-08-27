<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Listeners;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Events\NotificationSent;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Models\LegalNotice;
use Pushery\LegalConsent\Notifications\ChangeNotification;
use Pushery\LegalConsent\Support\SubjectKey;
use Pushery\LegalConsent\Support\SubjectToken;
use Pushery\LegalConsent\Support\TenantContext;

/**
 * Writes the append-only durable-medium proof row — and does it from the DELIVERY side.
 *
 * A change notice is queued ({@see ChangeNotification} is a `ShouldQueue`), so the sweep's
 * `Notification::send()` only hands a job to the queue. Writing the proof there certifies an
 * ENQUEUE, and the two facts come apart exactly where it matters: a dead worker, a poisoned job
 * or a refusing mail transport leaves the subject with no notice and the ledger with a row saying
 * one was served. `legal_notices` refuses every UPDATE (the model blocks it, PostgreSQL and MySQL
 * carry a BEFORE UPDATE trigger), so that row can never be corrected — and
 * `legal-consent:close-objection-windows` reads exactly those rows to bind a subject to a
 * contract change by silence (§ 308 Nr. 5 lit. b BGB).
 *
 * `NotificationSent` fires after the channel returned without throwing, so the row now states
 * what its own column says: the notice went out. Three further things follow from that, all of
 * them corrections rather than side effects:
 *
 *  - a subject the worker SKIPS gets no proof. {@see ChangeNotification::shouldSend()} runs in the
 *    worker and suppresses a re-consent notice for someone who agreed in the meantime; the sweep
 *    used to write their row anyway.
 *  - the row is rendered against the actual notifiable, not against the version standing in for
 *    one, so a consumer subclass whose `toMail()` reads the recipient is certified on what it
 *    really sent.
 *  - `AffectedSubjectResolver::forVersion(skipNotified: true)` now skips the DELIVERED rather than
 *    the merely queued, which is what makes `legal-consent:renotify` plus a re-dispatch a working
 *    repair path instead of a run that reports "Dispatched 0".
 *
 * WHAT THIS DOES NOT CLAIM: `delivered_at` stays null. The mailer accepting a message is not the
 * recipient receiving it, and a column that said otherwise would overstate the evidence.
 *
 * The cost is stated rather than hidden: the pseudonym lookup is per delivery here, where the
 * sweep resolved a whole chunk in one query per ledger. That batching is not recoverable once the
 * proof follows the job — the jobs are independent by construction — and a truthful row is worth
 * more than the round trip.
 */
final readonly class WriteNoticeDeliveryProof
{
    public function __construct(
        private TenantContext $tenant,
        private SubjectToken $tokens,
    ) {}

    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'mail') {
            return;
        }

        $notification = $event->notification;
        $subject = $event->notifiable;

        if (! $notification instanceof ChangeNotification || ! $subject instanceof Model) {
            return;
        }

        if (! filter_var(config('legal-consent.durable_medium.proof', true), FILTER_VALIDATE_BOOL)) {
            return;
        }

        $version = $notification->document;

        if (! $version->exists) {
            return;
        }

        // Read through getAttribute rather than the property: the column is NOT NULL with a default
        // of '', so a document created and notified in the same request — a publish followed by a
        // notify, with no round trip through the database — carries no value at all. The shared
        // bucket is what the absence means.
        $tenantId = $version->getAttribute(TenantContext::COLUMN);
        $tenantId = is_string($tenantId) ? $tenantId : '';

        // Pin that tenant around the pseudonym lookup AND the write. A worker runs
        // unauthenticated, so the ambient tenant resolves to the shared '' bucket: an unpinned
        // lookup would miss the subject's pseudonym and mint a fresh one — severing this proof
        // from their consent ledger — and an unpinned write would strand the row outside the
        // tenant it belongs to.

        $this->tenant->forTenant($tenantId, function () use ($subject, $version, $notification, $tenantId): void {
            $proof = $this->render($notification, $version, $subject);

            LegalNotice::query()->forceCreate([
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => SubjectKey::for($subject),
                'subject_token' => $this->tokens->forSubject($subject),
                // An explicitly-set value is honored (BelongsToTenant only stamps a null attribute).
                'tenant_id' => $tenantId,
                'document_id' => $version->getKey(),
                'document_key' => $version->key,
                'document_version' => $version->version,
                'document_major_version' => $version->major_version,
                'locale' => $version->locale,
                'notice_mode' => $version->noticeMode(),
                'medium' => $this->medium(),
                'notice_body' => $proof['body'],
                'notice_content_hash' => $proof['hash'],
                'mandatory_content_ok' => $proof['mandatory_ok'],
                'sent_at' => CarbonImmutable::now(),
            ]);
        });
    }

    /**
     * The notice as it was served, rendered in the version's locale.
     *
     * The locale is set explicitly rather than inherited. The sender already runs a queued
     * notification under its pinned locale, so this is normally a no-op — but the proof body is
     * hashed into a row nobody can correct, and inheriting a locale is not something to assume.
     *
     * @return array{body: string, hash: string, mandatory_ok: bool}
     */
    private function render(ChangeNotification $notification, LegalDocument $version, Model $subject): array
    {
        $original = app()->getLocale();
        app()->setLocale($version->locale);

        try {
            $mail = $notification->toMail($subject);

            /** @var list<string> $lines */
            $lines = array_values(array_filter([
                $mail->subject,
                ...$mail->introLines,
                $mail->actionText,
                ...$mail->outroLines,
            ], is_string(...)));

            // Must be evaluated INSIDE the version's locale — the same locale the notice was
            // rendered in — or it would certify a translation the subject never received. Ask the
            // notice itself: a non-empty body proves nothing (subject + intro alone keep it
            // non-empty while the § 308 Nr. 5 lit. b warning silently drops out).
            $mandatoryOk = $notification->mandatoryContentPresent();
        } finally {
            app()->setLocale($original);
        }

        $body = implode("\n", $lines);

        return [
            'body' => $body,
            'hash' => hash('sha256', $body),
            'mandatory_ok' => $mandatoryOk,
        ];
    }

    /**
     * What the proof calls the medium it went out on — read from `durable_medium.channels`, not
     * from the channel that delivered it, so an operator who declares a different durable medium
     * still gets the description they configured.
     */
    private function medium(): string
    {
        $channels = config('legal-consent.durable_medium.channels', ['mail']);
        $channels = is_array($channels) ? $channels : ['mail'];

        return in_array('mail', $channels, true) ? 'email' : 'durable_message';
    }
}
