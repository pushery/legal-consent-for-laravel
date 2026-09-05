<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Listeners;

use Illuminate\Notifications\Events\NotificationFailed;
use Pushery\LegalConsent\Notifications\ChangeNotification;

/**
 * A notice that could not be delivered is still OWED — so the version goes back on the sweep.
 *
 * The dispatch watermark says "this version's audience has been handed to the queue", and that is
 * all a synchronous run can honestly claim. When the channel then throws, the alternative to this
 * listener is a stamped version nobody will look at again: `dispatch-notices` selects on
 * `notified_at is null`, so the notice would sit undelivered with a green scheduled run over it.
 *
 * Clearing the watermark makes the next sweep pick the version up, and the durable-medium proof
 * keeps that from turning into a re-send storm:
 * `AffectedSubjectResolver::forVersion(skipNotified: true)` serves only the subjects with no
 * delivery on record. That is also why this is gated on the proof being on — without it there is
 * no resume marker, so re-opening the version would re-notify the WHOLE population on every sweep
 * for as long as the mail configuration stays broken. With proof off the operator's repair is
 * `legal-consent:renotify`, which is what that command exists for.
 */
final class ReopenVersionOnNoticeFailure
{
    public function handle(NotificationFailed $event): void
    {
        if ($event->channel !== 'mail') {
            return;
        }

        $notification = $event->notification;

        if (! $notification instanceof ChangeNotification) {
            return;
        }

        if (! filter_var(config('legal-consent.durable_medium.proof', true), FILTER_VALIDATE_BOOL)) {
            return;
        }

        $version = $notification->document;

        // The two halves are not the same kind of guard, and only one of them can be measured.
        //
        // `! $version->exists` is load-bearing: `forceFill()->saveQuietly()` on a model whose row
        // is gone does not update nothing, it INSERTS the row back -- measured, the count returns
        // and `exists` flips to true. Without it a refused mail transport re-creates a legal
        // document somebody deleted.
        //
        // `notified_at === null` cannot be observed at all, and is kept anyway. Eloquent skips the
        // statement when nothing is dirty -- measured: 0 UPDATEs when the watermark is already
        // null against 1 when it was stamped -- so dropping it changes no row and no query count.
        // It says the intent at the only place a reader looks, and it is what stops the
        // `saveQuietly()` below from being reached on a version this listener has nothing to do.
        if (! $version->exists || $version->notified_at === null) {
            return;
        }

        // `notified_at` is one of the columns that stay writable after publish — the content, the
        // version and the proof rows are untouched. Quietly, because a watermark is not a change
        // to the document itself.
        $version->forceFill(['notified_at' => null])->saveQuietly();
    }
}
