<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Carbon\CarbonImmutable;
use Pushery\LegalConsent\Exceptions\InvalidConfirmationWindow;
use Throwable;

/**
 * The window `legal-consent.double_opt_in.confirm_within` gives an opt-in request to be confirmed in.
 *
 * Read the way it is added to the request's time: an English relative time such as '7 days' or
 * '48 hours', or an ISO 8601 duration such as 'P7D'. A value that names no period, or one that ends
 * the window before the request, is refused with the key's name.
 */
final class ConfirmationWindow
{
    /**
     * The moment a request made at $requestedAt stops being confirmable.
     *
     * @throws InvalidConfirmationWindow when the value gives no window that ends after the request
     */
    public static function closesAt(string $window, CarbonImmutable $requestedAt): CarbonImmutable
    {
        try {
            $closes = $requestedAt->add($window);
        } catch (Throwable) {
            throw InvalidConfirmationWindow::unreadable($window);
        }

        if (! $closes->greaterThan($requestedAt)) {
            throw InvalidConfirmationWindow::notAfterTheRequest($window);
        }

        return $closes;
    }

    /**
     * Why the configured value gives no window, or null when it gives one or sets none.
     */
    public static function problem(mixed $window): ?string
    {
        if (! is_string($window) || trim($window) === '') {
            return null;
        }

        try {
            self::closesAt($window, CarbonImmutable::now());
        } catch (InvalidConfirmationWindow $refusal) {
            return $refusal->getMessage();
        }

        return null;
    }
}
