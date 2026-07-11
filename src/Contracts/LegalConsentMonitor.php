<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Contracts;

/**
 * Monitoring seam for the scheduled notice sweep. The package stays monitoring-agnostic;
 * a consumer binds a real implementation (wired to their metrics/heartbeat stack) that
 * records a heartbeat + pipeline status, because a missed re-consent notice is legally
 * relevant. The default binding is a no-op.
 */
interface LegalConsentMonitor
{
    /**
     * Record that a scheduled task ran and how many items it processed.
     */
    public function heartbeat(string $task, int $processed): void;
}
