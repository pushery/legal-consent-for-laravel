<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

/**
 * Reads a calendar date written as YYYY-MM-DD, and nothing else, and writes a date back the way a reader
 * of a language writes one.
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

    /**
     * The date as a reader of the language writes one, "March 25, 2027" in English and "25. März 2027"
     * in German, never in the ISO form a machine reads.
     *
     * A language Carbon has no long date format (`LL`) for gets the ISO date instead, which reads the
     * same everywhere. Carbon is asked for that format itself, with an empty string standing for none,
     * because the output of `isoFormat('LL')` cannot tell a missing format from a date: it is an empty
     * string from Carbon 3.14.2 on and the bare letter "L" before it.
     */
    public static function readable(CarbonImmutable $date, string $locale): string
    {
        $localized = $date->settings(['locale' => $locale]);

        return $localized->getTranslationMessage('formats.LL', default: '') !== ''
            ? $localized->isoFormat('LL')
            : $date->toDateString();
    }
}
