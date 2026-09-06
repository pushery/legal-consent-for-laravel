<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One active version per (key, locale, tenant) — enforced by the DATABASE on every engine, not
 * only on PostgreSQL.
 *
 * ⚠️ THIS CLOSES A GAP THAT WAS NAMED IN THE CODE AND THEN LIVED WITH FOR TWENTY-FOUR MIGRATIONS.
 * Migration 000001 creates the partial unique index on PostgreSQL and says in its own comment:
 * *"MySQL/SQLite rely on the app-layer guard in LegalDocument::activate()"*. That guard is a lock
 * taken on the configured CACHE store — and `array` is process-local, `null` grants every lock,
 * and a store that is no LockProvider cannot be asked at all. On those two engines the invariant
 * therefore rested on a component that is optional, swappable and not durable, and when it could
 * not serialize, the package logged a warning and wrote anyway.
 *
 * The invariant is not a convenience. It is the question the whole ledger answers: WHICH text was
 * in force when somebody agreed. Two rows with `is_active = true` do not degrade that answer, they
 * remove it — and no later repair can say which of the two a given acceptance was made against.
 *
 * **The obvious fix was to make the lock mandatory, and it was the worse one.** Refusing to publish
 * whenever the store cannot serialize breaks every test suite and every single-process deployment
 * — measured: 28 arms in this package's own suite — and it answers a missing CONSTRAINT with a
 * distributed lock. A constraint is what this is.
 *
 *  - **SQLite** takes the identical partial unique index PostgreSQL already has. It has supported
 *    them since 3.8.0 (2013); nothing about the original comment's reasoning was true by the time
 *    it was written.
 *  - **MySQL** has no partial index, so it gets the standard equivalent: a STORED generated column
 *    that is NULL for an inactive row and the identity triple for an active one, plus a plain
 *    unique index over it. NULLs do not collide in a unique index, so exactly the active rows are
 *    constrained.
 *
 * The cache lock stays exactly as it is, and becomes honest: it now turns a constraint violation
 * into an orderly wait rather than standing in for the constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        $table = $this->prefixed('legal_documents');
        $index = $this->prefixed('legal_documents_one_active_per_key_locale');

        if ($driver === 'sqlite') {
            // `IF NOT EXISTS` alone would be a check that passes while guarding nothing, because
            // the index EXISTING is not the same as the constraint existing. SQLite has no ALTER,
            // so Laravel implements one by rebuilding the table, and the rebuild reconstructs
            // indexes from a schema state that carries no WHERE clause: the partial index comes
            // back FULL, under the same name. Left in place it constrains every version of a key
            // rather than only the active one — stricter than intended, and wrong on a table whose
            // purpose is holding several versions.
            //
            // So the predicate is read back, not assumed. This repairs an installation that rolls
            // through a rebuild and re-runs this migration; it does NOT cover the case where a
            // LATER migration rebuilds the table and this one never runs again. A migration that
            // alters a column on this table has to re-create the index itself, exactly as
            // migration 000015 already re-installs the triggers a rebuild drops.
            $existingSql = DB::scalar(
                'select sql from sqlite_master where type = ? and name = ?',
                ['index', $index],
            );

            // `is_string` rather than a null check: no index yet is the ordinary case on a fresh
            // install, and it narrows the untyped catalog read at the same time.
            if (is_string($existingSql) && ! str_contains($existingSql, 'is_active')) {
                DB::statement("DROP INDEX {$index}");
            }

            // `is_active = 1` rather than `= true`: SQLite has no boolean type, and the column is
            // an integer. `true` is accepted as a keyword since 3.23 but the stored value is 1,
            // and an index predicate that does not match the stored value indexes nothing.
            DB::statement(
                "CREATE UNIQUE INDEX IF NOT EXISTS {$index} "
                ."ON {$table} (\"key\", locale, tenant_id) WHERE is_active = 1"
            );
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            // The separator is a character no identifier can contain, so ('a|b','c') and
            // ('a','b|c') cannot produce the same value — the same collision the activation lock
            // name had to be fixed for.
            //
            // COLLATE utf8mb4_bin, and it is not decoration. Migration 000022 put `key`, `locale`
            // and `tenant_id` on utf8mb4_bin precisely so the three engines agree on what "the
            // same document" means. A derived column takes the TABLE's collation instead, which
            // Laravel leaves at utf8mb4_unicode_ci — case- and accent-insensitive, PAD SPACE. The
            // index would then be stricter than the one PostgreSQL and SQLite carry: measured,
            // ('terms','de','') and ('TERMS','de',''), and a tenant told apart only by 'cafe' vs
            // 'café', are two rows on those engines and a duplicate-key error on MySQL. tenant_id
            // is consumer-supplied, so that is a real path rather than a constructed one — and it
            // would reintroduce, on the same table, exactly the divergence 000022 removed.
            // Guarded like migration 000024 guards both its halves. MySQL has no
            // `ADD COLUMN IF NOT EXISTS`, so a second run of this migration — a consumer
            // re-applying it by hand, or coming out of a partial rollback — died on
            // `ERROR 1060 Duplicate column name` while the SQLite arm beside it was idempotent.
            if (! Schema::hasColumn('legal_documents', 'active_identity')) {
                DB::statement(
                    "ALTER TABLE {$table} ADD COLUMN active_identity VARCHAR(600) COLLATE utf8mb4_bin "
                    .'GENERATED ALWAYS AS (IF(is_active, CONCAT(`key`, 0x1f, locale, 0x1f, tenant_id), NULL)) STORED'
                );
                DB::statement("CREATE UNIQUE INDEX {$index} ON {$table} (active_identity)");
            }
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        $table = $this->prefixed('legal_documents');
        $index = $this->prefixed('legal_documents_one_active_per_key_locale');

        if ($driver === 'sqlite') {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            // The index goes first: MySQL refuses to drop a column an index still references.
            if (Schema::hasColumn('legal_documents', 'active_identity')) {
                DB::statement("DROP INDEX {$index} ON {$table}");
                DB::statement("ALTER TABLE {$table} DROP COLUMN active_identity");
            }
        }
    }

    /**
     * A table or index name carrying the connection's table prefix.
     *
     * Same guard as migration 000001, and for the same reason: these names are interpolated into
     * DDL, and "a prefix cannot be hostile" is an assumption rather than a check.
     */
    private function prefixed(string $name): string
    {
        $prefix = DB::connection()->getTablePrefix();

        if (preg_match('/^\w*$/', $prefix) !== 1) {
            throw new RuntimeException("refusing to build legal_documents DDL for an unexpected table prefix: {$prefix}");
        }

        return $prefix.$name;
    }
};
