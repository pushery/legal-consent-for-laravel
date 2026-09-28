<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use RuntimeException;

/**
 * A source declared a date that is not a calendar date written as YYYY-MM-DD.
 *
 * The dates a source declares become the announcement and enforcement dates of the published
 * version, and a published row is append-only proof. A parser that reads '2026-02-31' as 3 March
 * would publish a date nobody wrote, and it could only be replaced by the next version. So the
 * pipeline refuses the date instead of guessing at it.
 */
final class InvalidDocumentDate extends RuntimeException
{
    public static function for(string $type, string $locale, string $field, string $value): self
    {
        return new self(
            "Legal document '{$type}' ({$locale}) declares {$field} '{$value}', which is not a calendar date written as YYYY-MM-DD (e.g. 2026-10-01). The date is frozen into the published version, so an unreadable one is refused rather than guessed at."
        );
    }
}
