<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Listeners;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\SentMessage;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Log;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Models\LegalNotice;
use Pushery\LegalConsent\Notifications\ChangeNotification;
use Pushery\LegalConsent\Support\NoticeAttempts;
use Pushery\LegalConsent\Support\SubjectKey;
use Pushery\LegalConsent\Support\SubjectToken;
use Pushery\LegalConsent\Support\TenantContext;
use Throwable;

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
 * `NotificationSent` fires after the channel returned without throwing, and the row is written only
 * when the mail channel handed back the message it sent, so it states what its own column says:
 * the notice went out. The channel returns without sending, and without an error, when the subject
 * has no mail route (no address, or a `routeNotificationForMail()` that answers null) or a
 * `MessageSending` listener cancels the message, and the event fires all the same. Nothing is
 * proved then; the attempt counts as failed ({@see ReopenVersionOnNoticeFailure::record()}), so the
 * subject is retried and, once the attempts are used up, reported as unreachable. Three further
 * things follow from writing on delivery, all of them corrections rather than side effects:
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
        private ReopenVersionOnNoticeFailure $failures,
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

        if (! $event->response instanceof SentMessage) {
            $this->failures->record($notification, $subject);

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

            // The token comes from the subject's row in the token registry, held until this write
            // commits, so a consent written for them at the same moment cannot mint a second one.
            $this->tokens->whileLocked($subject, fn (string $token): LegalNotice => LegalNotice::model()::query()->forceCreate([
                'subject_type' => (string) $subject->getMorphClass(),
                'subject_id' => SubjectKey::for($subject),
                'subject_token' => $token,
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
            ]));
        });

        // The proof row answers "was this subject served?" from here on, so the record of the
        // notice being on its way, and of any earlier failures, is no longer needed.
        NoticeAttempts::delivered($version, $subject);
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
            $body = implode("\n", $this->lines($notification, $subject));

            // Must be evaluated INSIDE the version's locale — the same locale the notice was
            // rendered in — or it would certify a translation the subject never received. Ask the
            // notice itself: a non-empty body proves nothing (subject + intro alone keep it
            // non-empty while the § 308 Nr. 5 lit. b warning silently drops out).
            $mandatoryOk = $notification->mandatoryContentPresent();

            $this->warnWhenTheBodyDependsOnItsRecipient($notification, $subject, $body);
        } finally {
            app()->setLocale($original);
        }

        return [
            'body' => $body,
            'hash' => hash('sha256', $body),
            'mandatory_ok' => $mandatoryOk,
        ];
    }

    /**
     * The lines of the notice as the mail channel sends them: the subject, the intro, the action
     * and the outro.
     *
     * @return list<string>
     */
    private function lines(ChangeNotification $notification, Model $notifiable): array
    {
        $mail = $notification->toMail($notifiable);

        $lines = [];

        foreach ([$mail->subject, ...$mail->introLines, $mail->actionText, ...$mail->outroLines] as $line) {
            // A line a notice formats as HTML is sent as that HTML, so it is recorded as such.
            // Dropped, the hash would certify a text that differs from the mail.
            if ($line instanceof Htmlable) {
                $line = $line->toHtml();
            }

            if (is_string($line)) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Warn, once per notice class and process, when the body depends on its recipient.
     *
     * The shipped notices build their text from the document alone, so the body is the same for
     * every recipient and names nobody. A notification class of the application's own may write
     * the recipient into its lines, and the proof keeps the body as it was sent: it is the
     * durable-medium proof, and its hash cannot be rewritten. Such a body therefore outlives
     * `forget()`, which is said here rather than found after an erasure request. Rendered once more
     * for a blank instance of the recipient's class, a body built from the document comes out the
     * same; a notice that cannot be rendered without its recipient depends on it as well. The check
     * never stands between a delivery and its proof.
     */
    private function warnWhenTheBodyDependsOnItsRecipient(ChangeNotification $notification, Model $subject, string $body): void
    {
        // The classes already reported in this process: a sweep over thousands of recipients logs
        // the finding once rather than once per row.
        /** @var array<class-string, true> $reported */
        static $reported = [];

        if (isset($reported[$notification::class])) {
            return;
        }

        try {
            $blank = implode("\n", $this->lines($notification, $subject->newInstance()));
        } catch (Throwable) {
            $blank = null;
        }

        if ($blank === $body) {
            return;
        }

        $reported[$notification::class] = true;

        Log::warning('legal-consent: a notice body depends on its recipient, so forget() leaves it in the delivery proof', [
            'notification' => $notification::class,
            'document_key' => $notification->document->key,
        ]);
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
