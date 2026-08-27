<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use RuntimeException;

/**
 * Thrown when a subject's primary key does not fit the proof tables' `subject_id` column.
 *
 * The column is a `varchar(64)` on both ledgers, and the three supported engines disagree about
 * a longer value: SQLite stores it whole (it enforces no column width), PostgreSQL raises
 * `22001 value too long for type character varying(64)` and MySQL in strict mode `1406 Data too
 * long`. Left to the engine, the same application is green in development and fails at its FIRST
 * ledger write in production — registration, re-consent, withdrawal and notice delivery at once —
 * with a driver error naming a column rather than a cause.
 */
final class SubjectKeyTooLong extends RuntimeException
{
    /**
     * The width `subject_id` was given on both proof tables (migrations 000002, 000006 and the
     * 000018 widening). It is the schema's number, restated here because the write path has to
     * refuse before it reaches the schema, and a check whose limit is invisible to the caller
     * cannot explain itself.
     */
    public const int MAX_LENGTH = 64;

    public static function for(string $subjectType, int $length): self
    {
        return new self(
            "The primary key of subject '{$subjectType}' is {$length} characters; the consent ledger stores it in a ".self::MAX_LENGTH.'-character column. Use a shorter key for this model — PostgreSQL and MySQL refuse the write outright, and SQLite would keep a value the other two cannot.'
        );
    }
}
