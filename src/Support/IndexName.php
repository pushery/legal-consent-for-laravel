<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The name of an index the package's migrations name themselves, led by the connection's table
 * prefix like every other name the package writes.
 *
 * Laravel uses a name it is given exactly as given, and on SQLite and PostgreSQL an index lives in
 * the database's or the schema's namespace rather than under its table. Without the prefix, two
 * installations under different prefixes in one database would both claim the same name, and the
 * second would fail to migrate. The migrations name these indexes because the name Laravel would
 * generate can pass the 64 characters MySQL allows.
 */
final class IndexName
{
    /** The name a migration gives the index now. */
    public static function of(string $name): string
    {
        return DB::connection()->getTablePrefix().$name;
    }

    /**
     * The name the index carries on this table: the prefixed one, or the bare one an installation
     * under a prefix received from a release that did not prefix these names yet. A rollback drops
     * whichever is there.
     */
    public static function existing(string $table, string $name): string
    {
        $prefixed = self::of($name);

        return $prefixed === $name || Schema::hasIndex($table, $prefixed) ? $prefixed : $name;
    }
}
