<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use InvalidArgumentException;
use Pushery\LegalConsent\Support\ConsentContext;

/**
 * Thrown when a value of a {@see ConsentContext} does not fit the `legal_consents` column it is
 * written to.
 *
 * The three supported engines disagree about such a value: SQLite stores it, PostgreSQL and MySQL
 * refuse the write with a driver error that names a column rather than a cause. Refused when the
 * context is built, the same application fails the same way on every engine, and the message names
 * the property to change.
 */
final class InvalidConsentContext extends InvalidArgumentException
{
    public static function tooLong(string $property, int $length, int $maxLength): self
    {
        return new self(
            "ConsentContext::\${$property} is {$length} characters; the consent ledger stores it in a {$maxLength}-character column. Pass a shorter value: PostgreSQL and MySQL refuse a longer one, and SQLite would keep a value the other two cannot."
        );
    }

    public static function userAgentTooLarge(int $bytes): self
    {
        return new self(
            "ConsentContext::\$userAgent is {$bytes} bytes; the consent ledger stores it in a text column, which holds ".ConsentContext::USER_AGENT_MAX_BYTES.' bytes on MySQL. Pass a shorter value: ConsentContext::fromRequest() keeps the first 1000 characters.'
        );
    }

    public static function notAnIpAddress(string $value): self
    {
        return new self(
            "ConsentContext::\$ipAddress is '{$value}', which is not an IP address. PostgreSQL stores the address in an inet column, which refuses anything else; pass null when there is no address."
        );
    }
}
