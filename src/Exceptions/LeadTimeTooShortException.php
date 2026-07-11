<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use Carbon\CarbonInterface;
use RuntimeException;

/**
 * A material change was scheduled with too little lead time between announcement and
 * enforcement. The default minimum (60 days) traces to § 675g Abs. 1 BGB ("spätestens
 * zwei Monate") and BGH XI ZR 26/20 — the only defensible statutory anchor for an
 * "angemessene Frist" on an intrusive change.
 */
final class LeadTimeTooShortException extends RuntimeException
{
    public static function for(string $documentKey, int $minDays, CarbonInterface $announceAt, CarbonInterface $enforceAt): self
    {
        return new self(sprintf(
            "Material change to '%s' needs at least %d days between announcement (%s) and enforcement (%s).",
            $documentKey,
            $minDays,
            $announceAt->toDateString(),
            $enforceAt->toDateString(),
        ));
    }
}
