<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The rule for a voluntary consent's registration control: any value a checkbox submits, ticked or
 * not, and nothing else.
 *
 * Ticked is what Laravel's `accepted` rule takes, the rule a mandatory document's control is held
 * to; not ticked is what its `declined` rule takes. A checkbox without a `value` attribute submits
 * `on`, which the `boolean` rule refuses. {@see RegistrationConsentRecorder} reads every value this
 * rule lets through the same way: a ticked one as given, an unticked one as not given. A value
 * outside both sets is refused, because a box ticked under a value of its own, such as `newsletter`,
 * would reach the recorder as not given.
 */
final class CheckboxAnswer implements ValidationRule
{
    /** What Laravel's `accepted` rule takes: the box was ticked. */
    public const array TICKED = ['yes', 'on', '1', 1, true, 'true'];

    /** What Laravel's `declined` rule takes: the box was submitted unticked. */
    public const array UNTICKED = ['no', 'off', '0', 0, false, 'false'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (in_array($value, self::TICKED, true) || in_array($value, self::UNTICKED, true)) {
            return;
        }

        $fail('legal-consent::validation.checkbox_answer')->translate();
    }
}
