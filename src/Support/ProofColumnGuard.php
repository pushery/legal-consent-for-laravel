<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pushery\LegalConsent\Models\LegalDocument;
use RuntimeException;

/**
 * Installs and removes the BEFORE UPDATE trigger that makes a published `legal_documents` row
 * physically un-editable.
 *
 * This lives in `src/` rather than inside the migration that first created it because it has
 * to be RE-INSTALLABLE, and that is not a refactoring preference — it is a portability fact
 * measured here on 2026-08-03. SQLite cannot alter a column in place: Laravel's `->change()`
 * performs the twelve-step rebuild (create a new table, copy, drop, rename), and while indexes
 * survive that, TRIGGERS DO NOT. So any later migration that touches a column on this table
 * silently disarms the proof guard on SQLite — no error, no failing migration, just a table
 * that stops being frozen.
 *
 * That failure was caught by `DocumentImmutabilityTest`, which asserts the guard from the
 * outside rather than trusting that a migration ran. Keep it that way: the guard here is the
 * mechanism, that test is the proof.
 *
 * On PostgreSQL the guard is a true allowlist (`to_jsonb(NEW) - <allowed>` compared to OLD), so
 * a proof column added by a future migration is protected automatically — it fails CLOSED.
 * MySQL and SQLite cannot diff a row minus columns inside a trigger, so they ENUMERATE every
 * protected column present at install time; a column added later would slip past them (fails
 * OPEN), which is the second reason re-installation matters and the reason
 * `DocumentImmutabilityTest` iterates the live column list rather than a written-down one.
 */
final class ProofColumnGuard
{
    public const string TRIGGER = 'legal_documents_no_proof_update';

    public const string FUNCTION = 'legal_documents_guard_proof';

    /**
     * (Re-)install the trigger for the connection's engine.
     *
     * Idempotent by construction: it drops any existing trigger first, so calling it after a
     * table rebuild — or after adding a proof column that the enumerating engines would
     * otherwise not know about — is always safe.
     */
    public static function install(): void
    {
        $driver = self::assertSupportedEngine();

        self::drop();

        match ($driver) {
            'pgsql' => self::installPostgres(),
            'mysql' => self::installMysql(),
            'sqlite' => self::installSqlite(),
        };
    }

    /**
     * The engine check, callable BEFORE anything is altered — which is the whole reason it is its
     * own method rather than the first lines of install().
     *
     * Fail loud on an engine this package does not know how to protect. The tempting arm is
     * `default => null`, and it is exactly wrong: a consumer on a fourth engine would get
     * migrations that succeed, a `legal_documents` table with no trigger on it, and no signal
     * anywhere — the immutability this package builds its evidentiary weight on, silently absent.
     * A migration that stops is recoverable; a proof table that only looks frozen is not.
     *
     * It also removes the one arm no test could ever reach. An unreachable `default` inside a
     * match is a line that can only be excluded, never covered, and excluding it would be the
     * second-best answer to a question that has a better one.
     *
     * Called first, a refusal costs the operator nothing but a message. Called after a schema
     * change — where it used to sit — it strands the table half-migrated on the MySQL family,
     * which commits DDL implicitly and so cannot roll the ALTER back: the column is already
     * altered, the `migrations` row was never written, and every re-run fails the same way.
     * Laravel's own driver name is the config value verbatim, so `mariadb` and `sqlsrv` reach
     * this literally rather than as `mysql`.
     *
     * The return type is the literal union rather than `string`, and that is load-bearing: it is
     * what lets the `match` in install() stay exhaustive without a `default` arm. A `default` there
     * would be a line no test can reach — coverable only by exclusion — which is the second-best
     * answer to a question that has a better one.
     *
     * @return 'mysql'|'pgsql'|'sqlite' the driver name, already proven to be one this guard can protect
     */
    public static function assertSupportedEngine(): string
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['pgsql', 'mysql', 'sqlite'], true)) {
            throw new RuntimeException(
                "legal_documents cannot be protected on the '{$driver}' driver: the proof-column trigger is written for PostgreSQL, MySQL and SQLite. Publishing legal texts without it would leave every frozen row editable, so this stops rather than continuing quietly."
            );
        }

        return $driver;
    }

    public static function drop(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS '.self::TRIGGER.' ON legal_documents;');

            return;
        }

        if ($driver === 'mysql' || $driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS '.self::TRIGGER.';');
        }
    }

    /**
     * Remove the trigger AND, on PostgreSQL, the function behind it.
     *
     * Separate from drop() on purpose: re-installing must not drop the function while another
     * statement could still reference it, and a rollback must not leave the function orphaned.
     */
    public static function uninstall(): void
    {
        self::drop();

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS '.self::FUNCTION.'();');
        }
    }

    /**
     * The proof columns present now — every column except the operational allowlist.
     *
     * These names are interpolated into the MySQL and SQLite trigger bodies below, so this is
     * the one place where the safety of that interpolation is decided. The source is the
     * database's own catalog rather than any request, but "it cannot be hostile" is an
     * assumption, and an assumption is not a guard: a name that is not a bare SQL identifier
     * stops the install here, before a single character of it reaches a statement.
     *
     * @return list<string>
     */
    private static function protectedColumns(): array
    {
        $columns = array_values(array_diff(
            array_filter(Schema::getColumnListing('legal_documents'), is_string(...)),
            LegalDocument::MUTABLE_AFTER_PUBLISH,
        ));

        foreach ($columns as $column) {
            if (preg_match('/^[A-Za-z_]\w*$/', $column) !== 1) {
                throw new RuntimeException(
                    "refusing to build the legal_documents proof trigger from an unexpected column name: {$column}"
                );
            }
        }

        return $columns;
    }

    private static function installPostgres(): void
    {
        // A dynamic allowlist: strip the four mutable keys from both row images and compare what
        // is left. A proof column added later is covered with no edit here (fails closed).
        $strip = implode('', array_map(
            static fn (string $column): string => " - '{$column}'",
            LegalDocument::MUTABLE_AFTER_PUBLISH,
        ));

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION legal_documents_guard_proof() RETURNS trigger AS \$\$
            BEGIN
                IF to_jsonb(NEW){$strip} IS DISTINCT FROM to_jsonb(OLD){$strip} THEN
                    RAISE EXCEPTION 'legal_documents rows are frozen proof — publish a new version instead (EDPB 05/2020 Rz. 108)';
                END IF;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER legal_documents_no_proof_update
                BEFORE UPDATE ON legal_documents
                FOR EACH ROW EXECUTE FUNCTION legal_documents_guard_proof();
            SQL);
    }

    private static function installMysql(): void
    {
        // Enumerated null-safe equality over every protected column: if any differs, SIGNAL.
        $sameCondition = implode(' AND ', array_map(
            static fn (string $column): string => "NEW.`{$column}` <=> OLD.`{$column}`",
            self::protectedColumns(),
        ));

        DB::unprepared(<<<SQL
            CREATE TRIGGER legal_documents_no_proof_update
                BEFORE UPDATE ON legal_documents
                FOR EACH ROW
            BEGIN
                IF NOT ({$sameCondition}) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'legal_documents rows are frozen proof — publish a new version instead (EDPB 05/2020 Rz. 108)';
                END IF;
            END;
            SQL);
    }

    private static function installSqlite(): void
    {
        // `IS NOT` is SQLite's null-safe distinctness; RAISE(ABORT) rolls back only the statement.
        $changedCondition = implode(' OR ', array_map(
            static fn (string $column): string => "NEW.\"{$column}\" IS NOT OLD.\"{$column}\"",
            self::protectedColumns(),
        ));

        DB::unprepared(<<<SQL
            CREATE TRIGGER legal_documents_no_proof_update
                BEFORE UPDATE ON legal_documents
                FOR EACH ROW
                WHEN ({$changedCondition})
            BEGIN
                SELECT RAISE(ABORT, 'legal_documents rows are frozen proof — publish a new version instead (EDPB 05/2020 Rz. 108)');
            END;
            SQL);
    }
}
