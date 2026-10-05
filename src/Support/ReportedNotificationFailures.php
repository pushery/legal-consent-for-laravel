<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Notifications\Events\NotificationFailed;
use Pushery\LegalConsent\Console\DispatchDueLegalNoticesCommand;
use Pushery\LegalConsent\Listeners\ReopenVersionOnNoticeFailure;
use Pushery\LegalConsent\Notifications\ChangeNotification;
use Throwable;
use WeakMap;

/**
 * The exceptions Laravel reported as a failed change notice, each with the channel it failed on.
 *
 * Laravel's notification sender reports a channel that throws as `NotificationFailed` and then
 * throws the same exception on. On a synchronous queue the notice runs inside
 * `Notification::send()`, so that exception reaches the sweep that sent it
 * ({@see DispatchDueLegalNoticesCommand}). This record is how the sweep tells such a refusal, which
 * ends one notice on one channel and is already counted ({@see ReopenVersionOnNoticeFailure}), from
 * any other exception, which ends the run.
 *
 * Keyed weakly by the exception object: an entry goes when its exception does, so a long-running
 * process accumulates nothing.
 */
final class ReportedNotificationFailures
{
    /** @var WeakMap<Throwable, string> */
    private WeakMap $channels;

    public function __construct()
    {
        $this->channels = new WeakMap;
    }

    public function handle(NotificationFailed $event): void
    {
        $exception = $event->data['exception'] ?? null;

        if ($event->notification instanceof ChangeNotification && $exception instanceof Throwable) {
            $this->channels[$exception] = $event->channel;
        }
    }

    /**
     * The channel a change notice failed on with exactly this exception, or null when Laravel
     * reported no such failure.
     */
    public function channelOf(Throwable $exception): ?string
    {
        return $this->channels[$exception] ?? null;
    }
}
