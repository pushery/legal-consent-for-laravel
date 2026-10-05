<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use RuntimeException;

/**
 * `legal-consent.double_opt_in.confirm_within` names no window an opt-in request can be confirmed in.
 *
 * The value is the operator's to fix, so the refusal names the key. Read as written, an unreadable
 * value failed every confirmation with the date library's own error, and one that ends before the
 * request turned every confirmation away as late, which reads like the subject's mistake.
 */
final class InvalidConfirmationWindow extends RuntimeException
{
    public static function unreadable(string $window): self
    {
        return new self(
            "legal-consent.double_opt_in.confirm_within is '{$window}', which is not a period. Use an English relative time such as '7 days' or an ISO 8601 duration such as 'P7D', or null for no limit."
        );
    }

    public static function notAfterTheRequest(string $window): self
    {
        return new self(
            "legal-consent.double_opt_in.confirm_within is '{$window}', which ends the window before the request it is counted from, so every confirmation would count as late. Use a positive period such as '7 days' or 'P7D', or null for no limit."
        );
    }
}
