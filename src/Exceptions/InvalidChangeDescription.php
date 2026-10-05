<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use InvalidArgumentException;
use Pushery\LegalConsent\Support\PendingChangeItems;

/**
 * Thrown when a value of a change description does not fit the column it is written to.
 *
 * The supported engines disagree about such a value: SQLite stores any length, while PostgreSQL
 * refuses a string longer than its column and MySQL also a text over 65,535 bytes, with a driver
 * error that names a column rather than a cause. Refused when the value is described, the same
 * description fails the same way on every engine, before anything is written, and the message
 * names the argument to change.
 */
final class InvalidChangeDescription extends InvalidArgumentException
{
    public static function tooLong(string $argument, int $length, int $maxLength): self
    {
        return new self(
            "The change description's \${$argument} is {$length} characters; it is stored in a {$maxLength}-character column. Pass a shorter value and put the rest into \$detail: PostgreSQL and MySQL refuse a longer one, and SQLite would keep a value the other two cannot."
        );
    }

    public static function tooLarge(string $argument, int $bytes): self
    {
        return new self(
            "The change description's \${$argument} is {$bytes} bytes; it is stored in a text column, which holds ".PendingChangeItems::TEXT_MAX_BYTES.' bytes on MySQL. Pass a shorter value: MySQL refuses a longer one, and PostgreSQL and SQLite would keep a value MySQL cannot.'
        );
    }
}
