<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Pushery\LegalConsent\Contracts\LegalConsentMonitor;

/**
 * Default no-op monitor. Consumers bind their own to wire heartbeats / pipeline status.
 */
final class NullMonitor implements LegalConsentMonitor
{
    public function heartbeat(string $task, int $processed): void
    {
        // Intentionally does nothing.
    }
}
