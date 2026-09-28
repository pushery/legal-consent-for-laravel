<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

/**
 * Reads a calendar date written as YYYY-MM-DD, and nothing else.
 *
 * The dates read this way are legal deadlines: when subjects are told about a new version, when it
 * binds, and when silence starts to count as consent. Each is frozen into an append-only row once
 * published, so a wrong one cannot be corrected afterwards, only superseded by a new version.
 *
 * `CarbonImmutable::parse()` never refuses such a date: '2026-02-31' becomes 2026-03-03, so does
 * '31.02.2026', and 'x' becomes the current time. `createFromFormat('!Y-m-d', …)` refuses the last
 * two but still rolls a well-formed impossible date over, '2026-02-31' to 2026-03-03 and '2026-13-01'
 * to 2027-01-01. So the format is the first half of the check, and the second is that the date
 * prints back as exactly what was written. The leading `!` resets the time, so two dates written the
 * same way compare the same way.
 */
final class CalendarDate
{
    /**
     * The date the value names, or null when it is not a calendar date written as YYYY-MM-DD.
     */
    public static function parse(string $value): ?CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (InvalidFormatException) {
            return null;
        }

        // In Carbon's strict mode createFromFormat() throws rather than returning null; its return
        // type still allows null, so the check stays.
        if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $date;
    }
}
