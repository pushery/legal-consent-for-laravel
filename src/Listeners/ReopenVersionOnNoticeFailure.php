<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Events\NotificationFailed;
use Pushery\LegalConsent\Notifications\ChangeNotification;
use Pushery\LegalConsent\Support\NoticeAttempts;

/**
 * A notice that could not be delivered is still OWED — so it is recorded, and the version goes back
 * on the sweep while that subject has attempts left.
 *
 * The dispatch watermark says "this version's audience has been handed to the queue", and that is
 * all a synchronous run can honestly claim. When the channel then throws, the alternative to this
 * listener is a stamped version nobody will look at again: `dispatch-notices` selects on
 * `notified_at is null`, so the notice would sit undelivered with a green scheduled run over it.
 *
 * The failure is counted against the subject and the version ({@see NoticeAttempts}), and that
 * count is what keeps a retry from turning into a re-send storm. The next sweep serves only the
 * subjects with no delivery on record, none whose notice is still in the queue, and none that has
 * failed `notifications.max_attempts` times: an address the transport refuses on every attempt is
 * tried that often and then reported as unreachable on every run, rather than sent a fresh notice
 * on every run. A failure that arrives while the sweep of the same version is still running is
 * counted all the same, and the sweep that follows picks it up.
 *
 * All of this is gated on the durable-medium proof being on: without it there is no resume marker,
 * so re-opening the version would re-notify the WHOLE population on every sweep for as long as the
 * mail configuration stays broken. With proof off the operator's repair is
 * `legal-consent:renotify`, which is what that command exists for.
 */
final class ReopenVersionOnNoticeFailure
{
    public function handle(NotificationFailed $event): void
    {
        if ($event->channel !== 'mail') {
            return;
        }

        $this->record($event->notification, $event->notifiable);
    }

    /**
     * Count a notice that did not go out against its subject, and put the version back on the
     * sweep while the subject has attempts left.
     *
     * Also called for a mail the channel skipped without an error, which fires `NotificationSent`
     * rather than `NotificationFailed` ({@see WriteNoticeDeliveryProof}).
     */
    public function record(object $notification, mixed $notifiable): void
    {
        if (! $notification instanceof ChangeNotification) {
            return;
        }

        if (! filter_var(config('legal-consent.durable_medium.proof', true), FILTER_VALIDATE_BOOL)) {
            return;
        }

        $version = $notification->document;

        // A version whose row is gone is left alone: `forceFill()->saveQuietly()` on a model whose
        // row was deleted does not update nothing, it INSERTS the row back, and a refused mail
        // transport would re-create a legal document somebody deleted.
        if (! $version->exists) {
            return;
        }

        $failures = $notifiable instanceof Model ? NoticeAttempts::failed($version, $notifiable) : 0;

        // A subject that has failed as often as it may is not retried, so re-opening the version
        // for it would only have the next sweep find nobody to serve. The dispatch command reports
        // it instead, on every run, until it is reached another way or forgotten.
        if ($failures >= NoticeAttempts::maxAttempts()) {
            return;
        }

        // A clear watermark means the version is due already, or its sweep has not finished and
        // stamps the watermark when it does. In that case the failure recorded above is what brings
        // the version back, so there is nothing to clear here.
        if ($version->notified_at === null) {
            return;
        }

        // `notified_at` is one of the columns that stay writable after publish — the content, the
        // version and the proof rows are untouched. Quietly, because a watermark is not a change
        // to the document itself.
        $version->forceFill(['notified_at' => null])->saveQuietly();
    }
}
