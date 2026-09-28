<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Carbon\CarbonImmutable;
use DateInterval;
use Pushery\LegalConsent\Exceptions\InvalidRetentionPeriod;
use Throwable;

/**
 * The retention period `legal-consent:prune` deletes by, from `legal-consent.retention_after_end`.
 *
 * PHP reads a relative time without complaint far more often than it reads it correctly. With the
 * sweep's own minus in front, `3 Jahre`, `3y` and `3 yrs` leave the date where it is, and
 * `3 years ago` and `-3 years` move it three years into the future. A cutoff at or after now makes
 * every superseded record past its retention. So a value counts as a period only when the cutoff it
 * gives lies at least a day in the past, and anything else is refused before a row is deleted.
 */
final class RetentionPeriod
{
    public const string DEFAULT = '3 years';

    /** The configured value: a non-empty string, or the default for anything else. */
    public static function configured(mixed $value): string
    {
        return is_string($value) && $value !== '' ? $value : self::DEFAULT;
    }

    /**
     * The instant before which a record is past its retention.
     *
     * An ISO 8601 duration (`P3Y`) is subtracted as an interval, anything else is read as a
     * relative time (`3 years`) the way the sweep always read it.
     *
     * @throws InvalidRetentionPeriod when the value gives no cutoff at least a day before now
     */
    public static function cutoff(string $retention, CarbonImmutable $now): CarbonImmutable
    {
        try {
            $cutoff = str_starts_with($retention, 'P')
                ? $now->sub(new DateInterval($retention))
                : $now->modify("-{$retention}");
        } catch (Throwable) {
            throw InvalidRetentionPeriod::unreadable($retention);
        }

        if ($cutoff->greaterThan($now->subDay())) {
            throw InvalidRetentionPeriod::notInThePast($retention, $cutoff, $now);
        }

        return $cutoff;
    }

    /** Why this value gives no retention cutoff, or null when it gives one. */
    public static function problem(string $retention, CarbonImmutable $now): ?string
    {
        try {
            self::cutoff($retention, $now);
        } catch (InvalidRetentionPeriod $refusal) {
            return $refusal->getMessage();
        }

        return null;
    }
}
