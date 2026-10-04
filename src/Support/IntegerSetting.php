<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

/**
 * A whole-number setting, read the way a configuration hands it over.
 *
 * `env()` returns every number as a string: `'threshold' => env('AGE_GATE_THRESHOLD', 16)` yields
 * `'18'` when the environment sets 18. Checked with `is_int()` alone, that value counts as unset
 * and the default applies in its place, which reads exactly like a value the operator chose: an
 * age threshold or a notice period other than the configured one.
 *
 * So a string that spells a whole number counts as that number. Anything else that is not an
 * integer is no value, and the caller's default applies; `legal-consent:doctor` names such a
 * setting rather than leaving the substitution silent.
 */
final class IntegerSetting
{
    public static function from(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
    }
}
