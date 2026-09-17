<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Database\Eloquent\Model;
use Pushery\LegalConsent\Testing\RecordedConsent;

/**
 * The string form of a subject's primary key — the form `subject_id` stores.
 *
 * `subject_id` became a `varchar(64)` in 0.18.0 so that a UUID- or ULID-keyed subject fits
 * alongside an auto-increment id. What did not move with it was the BINDING. A model with an
 * integer key hands `getKey()` back as a PHP int; Laravel binds an int as `PDO::PARAM_INT`
 * (native prepares — the connector disables emulation), and MySQL compares a `varchar` column
 * against a numeric operand by casting BOTH sides to floating point. Such a comparison cannot
 * use an index on the string column, so the gate's per-request query degrades to a full scan of
 * the ledger. It also matches too much: `2`, `'02'`, `' 2'` and `'2.0'` are one value to a
 * numeric comparison and four different subjects to this table.
 *
 * Two engines hide it, which is why the fast suites stayed green. SQLite applies column affinity
 * and converts the operand to text; the pgsql driver sends parameters untyped and PostgreSQL
 * infers `varchar` from the column. Only MySQL pays — the engine Laravel Cloud runs.
 *
 * The narrowing lives here, once, for the same reason {@see RecordedConsent::keyOf()}
 * gives: narrowing it at each call site is how a subject gets stored under one key and looked up
 * under another.
 */
final class SubjectKey
{
    /**
     * Null when the key has no lossless string form — the same refusal the v1 backfill and the
     * hash chain make, rather than casting a float or an object into a value that would silently
     * name a different subject.
     */
    public static function for(Model $subject): ?string
    {
        return self::from($subject->getKey());
    }

    public static function from(mixed $key): ?string
    {
        return is_int($key) || is_string($key) ? (string) $key : null;
    }

    /**
     * The map key for a (subject type, subject key) pair — the form a reader over MANY subjects
     * has to index by, because the key alone does not name a subject: a `User` and a `Team` can
     * both be number 1, and a map keyed by the id would hand one of them the other's answer.
     *
     * A NUL byte cannot appear in a morph alias or in a primary key, so the pair never collides
     * the way a `:` or a `-` would against a key that contains one.
     *
     * It lives here rather than beside its first caller for the reason this whole class exists:
     * a separator spelled at two call sites is how a subject gets stored under one key and looked
     * up under another.
     */
    public static function pair(string $type, string $key): string
    {
        return $type."\0".$key;
    }

    /**
     * Null for the same reason {@see for()} is: a key with no lossless string form names no
     * subject, so it gets no entry rather than one under a value that would name a different one.
     */
    public static function pairFor(Model $subject): ?string
    {
        $key = self::for($subject);

        return $key === null ? null : self::pair((string) $subject->getMorphClass(), $key);
    }
}
