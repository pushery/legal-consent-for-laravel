<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use Carbon\CarbonInterface;
use RuntimeException;

/**
 * A retention setting names no retention period the sweep can delete by: `legal-consent.retention_after_end`,
 * or a document's entry in `legal-consent.retention_by_document`.
 *
 * The sweep deletes every superseded or orphaned record accepted before the cutoff this value
 * gives. A value that puts the cutoff at or after now makes every such record past its
 * retention, so refusing it is what keeps the proof the period exists to protect.
 */
final class InvalidRetentionPeriod extends RuntimeException
{
    public static function unreadable(string $retention, string $key = 'legal-consent.retention_after_end'): self
    {
        return new self(
            "{$key} is '{$retention}', which is not a period. Use an English relative time such as '3 years' or an ISO 8601 duration such as 'P3Y'."
        );
    }

    public static function notInThePast(string $retention, CarbonInterface $cutoff, CarbonInterface $now, string $key = 'legal-consent.retention_after_end'): self
    {
        return new self(
            "{$key} is '{$retention}', which puts the retention cutoff at {$cutoff->toIso8601String()}, less than a day before now ({$now->toIso8601String()}), so every superseded record would count as expired. Use an English relative time such as '3 years' or an ISO 8601 duration such as 'P3Y'."
        );
    }
}
