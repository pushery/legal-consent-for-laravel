<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use RuntimeException;

/**
 * Thrown when a tenant id does not fit the `tenant_id` column of the package's tables.
 *
 * The column is a `varchar(64)` on every table that carries it, while the tenant id comes from the
 * application's resolver and its own tenant table, which may hold it wider. The three supported
 * engines disagree about a longer value: SQLite stores it whole (it enforces no column width),
 * PostgreSQL raises `22001 value too long for type character varying(64)` and MySQL in strict mode
 * `1406 Data too long`. Left to the engine, the same application is green in development and fails
 * at its first write in production, with a driver error naming a column rather than a cause.
 */
final class TenantIdTooLong extends RuntimeException
{
    /**
     * The width `tenant_id` was given on every table that carries it. It is the schema's number,
     * restated here because the tenant is refused where it is resolved, before any write reaches the
     * schema, and a check whose limit is invisible to the caller cannot explain itself.
     */
    public const int MAX_LENGTH = 64;

    public static function from(string $resolver, int $length): self
    {
        return new self(
            "The tenant id from {$resolver}() is {$length} characters; the package stores it in a ".self::MAX_LENGTH.'-character column. Map the tenant to a shorter key in the resolver: PostgreSQL and MySQL refuse the write outright, and SQLite would keep a value the other two cannot.'
        );
    }
}
