<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen `subject_id` on both proof tables from an integer key to a 64-character string one, so a
 * consuming application can key its subjects by UUID or ULID rather than only by auto-increment.
 *
 * The tables shipped `unsignedBigInteger`, and there was no seam a consumer could bend: no config
 * key, no cast, no overridable attribute. PostgreSQL answers a UUID with `invalid input syntax for
 * type bigint` and MySQL with `Data truncated for column 'subject_id'` — on the very first read of
 * the token lookup, so registration, re-consent, withdrawal and notice delivery all fail together.
 *
 * WHY THIS DOES NOT RE-CHAIN A SINGLE EXISTING ROW, which is the question that decides whether the
 * change is safe at all. `LedgerHashChain::canonical()` length-prefixes the STRING cast of every
 * proof field precisely so a column returned as `2` by one PDO driver and `'2'` by another hashes
 * the same. An integer key and its decimal text therefore produce an identical canonical form, so
 * the tamper-evidence chain reads exactly as it did before the type moved. Asserted rather than
 * assumed, by each engine suite, against rows written before the type moved.
 *
 * PER-DRIVER SQL RATHER THAN `->change()`, and only for PostgreSQL: PostgreSQL refuses to widen
 * `bigint` to `varchar` without a `USING` clause ("column cannot be cast automatically"), and the
 * schema builder emits none. MySQL and SQLite take the portable path.
 *
 * SQLite REBUILDS THE TABLE to change a column, and a rebuild keeps the indexes but DROPS the
 * triggers (see 000011). That is safe HERE and the reason is worth stating rather than leaving to
 * be rediscovered: neither `legal_consents` nor `legal_notices` carries a SQLite trigger — their
 * append-only guards have a pgsql arm and a mysql arm and nothing else, because SQLite gets the
 * same guarantee from the model layer. The unique chain-link index from 000012 survives the
 * rebuild, and the suite asserts it still bites afterwards.
 */
return new class extends Migration
{
    /** The proof tables carrying a polymorphic subject reference. */
    private const array TABLES = ['legal_consents', 'legal_notices'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if ($this->holdsStringKeys($table)) {
                continue;
            }

            $this->widen($table);
        }
    }

    /**
     * Narrow back to an integer key.
     *
     * This can legitimately FAIL, and that is the honest behavior rather than a defect: once a
     * subject with a UUID key has consented, no integer column can hold that proof, and silently
     * dropping or zeroing it would destroy evidence the ledger exists to keep (Art. 5(2)). A
     * consumer who has only ever used integer keys rolls back cleanly; one who has not must not.
     */
    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! $this->holdsStringKeys($table)) {
                continue;
            }

            $this->narrow($table);
        }
    }

    /**
     * Is the column ALREADY a string type? A fresh install runs the create migration, which now
     * declares the wide column, and then reaches this one — so without the check every new
     * installation would rewrite two tables to change nothing.
     */
    private function holdsStringKeys(string $table): bool
    {
        $type = strtolower(Schema::getColumnType($table, 'subject_id'));

        return str_contains($type, 'char') || str_contains($type, 'text') || $type === 'string';
    }

    private function widen(string $table): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN subject_id TYPE varchar(64) USING subject_id::varchar");

            return;
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->string('subject_id', 64)->nullable()->change();
        });
    }

    private function narrow(string $table): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN subject_id TYPE bigint USING subject_id::bigint");

            return;
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->unsignedBigInteger('subject_id')->nullable()->change();
        });
    }
};
