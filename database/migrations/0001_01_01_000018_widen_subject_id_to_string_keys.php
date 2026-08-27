<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pushery\LegalConsent\Support\ProofColumnGuard;

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
 * triggers (see 000011). Neither `legal_consents` nor `legal_notices` carries a trigger of its own
 * on that engine — their append-only guards have a pgsql arm and a mysql arm and nothing else,
 * because SQLite gets the same guarantee from the model layer. The unique chain-link index from
 * 000012 survives the rebuild, and the suite asserts it still bites afterwards.
 *
 * What is NOT safe, and is why the rebuild runs inside `ProofColumnGuard::whileDisarmed()`: the
 * guard on `legal_documents` names `legal_consents` in its DELETE arm, and the rebuild's last step
 * renames the replacement table into place while the original is already gone. SQLite re-parses
 * every trigger in the schema at that rename, so a trigger pointing at the missing table is a hard
 * error — measured: "error in trigger legal_documents_no_referenced_delete: no such table:
 * main.legal_consents", raised AFTER `legal_consents` has been dropped. Disarming first turns a
 * destroyed ledger table into an ordinary schema change.
 */
return new class extends Migration
{
    /** The proof tables carrying a polymorphic subject reference. */
    private const array TABLES = ['legal_consents', 'legal_notices'];

    public function up(): void
    {
        ProofColumnGuard::whileDisarmed(function (): void {
            foreach (self::TABLES as $table) {
                if ($this->holdsStringKeys($table)) {
                    continue;
                }

                $this->widen($table);
            }
        });
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
        ProofColumnGuard::whileDisarmed(function (): void {
            foreach (self::TABLES as $table) {
                if (! $this->holdsStringKeys($table)) {
                    continue;
                }

                $this->narrow($table);
            }
        });
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
            $qualified = $this->prefixed($table);

            DB::statement("ALTER TABLE {$qualified} ALTER COLUMN subject_id TYPE varchar(64) USING subject_id::varchar");

            return;
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->string('subject_id', 64)->nullable()->change();
        });
    }

    private function narrow(string $table): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $qualified = $this->prefixed($table);

            DB::statement("ALTER TABLE {$qualified} ALTER COLUMN subject_id TYPE bigint USING subject_id::bigint");

            return;
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->unsignedBigInteger('subject_id')->nullable()->change();
        });
    }

    /**
     * A table name carrying the connection's table prefix.
     *
     * `Schema::table()` in the portable branch applies it for us; the hand-written PostgreSQL
     * statement is the only branch here that does not, which is exactly the shape of the defect —
     * the portable path stays green while the engine-specific one names a table the schema does
     * not have.
     *
     * THE PREFIX IS VALIDATED BEFORE THIS METHOD CAN RUN, AND NOT HERE. Sister migrations refuse a
     * prefix that is not a bare identifier fragment on the spot, because they build their DDL as
     * the first thing they do. This one cannot be reached that way: every caller sits inside
     * `ProofColumnGuard::whileDisarmed()`, whose first act is `drop()` -> `qualify()`, which
     * applies the identical `/^\w*$/` rule and throws. This method used to repeat that check, and
     * the copy could not fire — measured 2026-08-27, `up()` on a prefix of `legal-` raises
     * "refusing to build the legal_documents guards for an unexpected table prefix", never this
     * file's own sentence. A branch no run can enter is not a defense; it only reads like one.
     *
     * So the guarantee lives one level up, and the suite pins it there rather than here: both
     * halves are run on a hostile prefix and the refusal is required to arrive BEFORE any statement
     * is issued. Take `whileDisarmed()` off this migration and that goes red, which is the point —
     * the wrapper is what makes the interpolation below safe, on top of the rebuild hazard it was
     * added for.
     */
    private function prefixed(string $name): string
    {
        return DB::connection()->getTablePrefix().$name;
    }
};
