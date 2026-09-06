<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pushery\LegalConsent\Models\LegalDocument;
use RuntimeException;

/**
 * Installs and removes the two triggers that make a published `legal_documents` row physically
 * un-editable: a BEFORE UPDATE guard over its proof columns, and a BEFORE DELETE guard over any
 * row a ledger entry still points at.
 *
 * The DELETE half exists because the UPDATE half alone protects the wrong thing. `legal_consents`
 * carries a `content_hash` and the one acceptance sentence, never the text itself — the full text
 * exists exactly once, in `legal_documents.content`. A hash can verify a text somebody produces;
 * it cannot produce one. So deleting a superseded version leaves every consent recorded against it
 * holding a fingerprint of a document nobody has any more, which is precisely the Art. 7(1)
 * evidence the ledger is built to carry. The subordinate `legal_change_sets` tables have had a
 * BEFORE DELETE guard since they were introduced; the load-bearing table had none.
 *
 * The guard is CONDITIONAL, and the condition is the point: a version no subject ever consented to
 * is still deletable. Refusing every delete would make the guard something operators route around
 * rather than something they keep — retirement runs through `is_active = false`, which is what that
 * column is for, and a version nobody is bound by is ordinary data.
 *
 * The DELETE half names a second table, and on SQLite that has a cost every future migration has
 * to pay: a migration that rebuilds `legal_consents` or `legal_documents` must run inside
 * {@see self::whileDisarmed()}, or it dies mid-rebuild with the original table already dropped.
 * The full mechanism and the measurement are documented on that method.
 *
 * This lives in `src/` rather than inside the migration that first created it because it has
 * to be RE-INSTALLABLE, and that is not a refactoring preference — it is a portability fact
 * measured here on 2026-08-03. SQLite cannot alter a column in place: Laravel's `->change()`
 * performs the twelve-step rebuild (create a new table, copy, drop, rename), and while indexes
 * survive that, TRIGGERS DO NOT. So any later migration that touches a column on this table
 * silently disarms the proof guard on SQLite — no error, no failing migration, just a table
 * that stops being frozen.
 *
 * That failure was caught by a test that asserts the guard from the
 * outside rather than trusting that a migration ran. Keep it that way: the guard here is the
 * mechanism, that test is the proof.
 *
 * On PostgreSQL the guard is a true allowlist (`to_jsonb(NEW) - <allowed>` compared to OLD), so
 * a proof column added by a future migration is protected automatically — it fails CLOSED.
 * MySQL and SQLite cannot diff a row minus columns inside a trigger, so they ENUMERATE every
 * protected column present at install time; a column added later would slip past them (fails
 * OPEN), which is the second reason re-installation matters and the reason that test iterates
 * the live column list rather than a written-down one.
 */
final class ProofColumnGuard
{
    public const string TRIGGER = 'legal_documents_no_proof_update';

    public const string FUNCTION = 'legal_documents_guard_proof';

    public const string DELETE_TRIGGER = 'legal_documents_no_referenced_delete';

    public const string DELETE_FUNCTION = 'legal_documents_guard_delete';

    /**
     * Kept under MySQL's 128-character limit for `SIGNAL … SET MESSAGE_TEXT`, which truncates
     * silently rather than erroring — a guard whose sentence is cut in half still refuses, but
     * stops telling the operator what to do instead.
     */
    private const string DELETE_MESSAGE = 'a legal_documents row a consent points at IS the proof text: retire it with is_active = false, never DELETE (Art. 7(1))';

    /**
     * (Re-)install the triggers for the connection's engine.
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

    /**
     * Take the guards off, run a schema change, put them back.
     *
     * This is not convenience, it is the safe form of a hazard SQLite makes real. The DELETE guard
     * names a SECOND table, and SQLite has no way to alter a column in place: Laravel changes one
     * by creating a replacement table, copying, dropping the original and RENAMING the replacement
     * into its place. That final rename re-parses every trigger in the schema — and a trigger
     * whose referenced table is missing at that instant is a hard error. Measured on 2026-08-27
     * against SQLite 3.45.2: rebuilding `legal_consents` with this guard installed fails with
     * "error in trigger legal_documents_no_referenced_delete: no such table: main.legal_consents",
     * AFTER the original has already been dropped. The ledger table is gone and the migration is
     * halfway through — the worst outcome in the package, produced by the guard meant to protect
     * it.
     *
     * So ANY migration that rebuilds `legal_consents` or `legal_documents` runs its schema work
     * inside this call. The four that already do (000014, 000018, 000022, 000028) are the working
     * examples. That list is derived from the migration directory by a guard rather than trusted
     * here: it said "two" while three did it, and it is the sentence the author of the next one
     * reads before deciding whether they need the wrapper.
     *
     * The re-install sits in `finally` deliberately: a schema change that throws must not also
     * leave the proof table unguarded, and an unguarded `legal_documents` is a state with no
     * symptom — nothing fails, rows simply stop being frozen.
     */
    public static function whileDisarmed(callable $work): void
    {
        self::drop();

        try {
            $work();
        } finally {
            self::install();
        }
    }

    public static function drop(): void
    {
        $driver = DB::connection()->getDriverName();
        $documents = self::qualify('legal_documents');
        $update = self::qualify(self::TRIGGER);
        $delete = self::qualify(self::DELETE_TRIGGER);

        if ($driver === 'pgsql') {
            self::execute("DROP TRIGGER IF EXISTS {$update} ON {$documents};");
            self::execute("DROP TRIGGER IF EXISTS {$delete} ON {$documents};");

            return;
        }

        // `mariadb` is named on the DROP side only, and deliberately. The engine is not supported
        // -- assertSupportedEngine() refuses it -- but 0.13.0 briefly did install these triggers on
        // it, and an installation carrying them must still be able to take them off. Naming it here
        // costs an IF EXISTS that matches nothing everywhere else.
        if (in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            self::execute("DROP TRIGGER IF EXISTS {$update};");
            self::execute("DROP TRIGGER IF EXISTS {$delete};");
        }
    }

    /**
     * Remove the triggers AND, on PostgreSQL, the functions behind them.
     *
     * Separate from drop() on purpose: re-installing must not drop the function while another
     * statement could still reference it, and a rollback must not leave the function orphaned.
     */
    public static function uninstall(): void
    {
        self::drop();

        // ⚠️ A RUN THAT ONLY SEES SQLITE CANNOT REACH THESE TWO, AND THE REASON IS THE DRIVER
        // CHECK RATHER THAN A MISSING TEST. This branch is entered only on a `pgsql` connection,
        // so nothing inside it executes anywhere else. They ARE exercised — the PostgreSQL
        // reversibility arm rolls this migration and asserts each guard function by name.
        //
        // ⚠️ Until that arm was written they were executed by NOTHING, anywhere: no reversibility
        // test named this migration, and `uninstall()` has exactly one caller. So the gap was real
        // while looking like ordinary engine-scope noise — which is the argument for reading such
        // cases rather than dismissing them wholesale.
        //
        // Worth recognizing as a CLASS rather than as three lines: every engine-specific branch in
        // this package has the same property. A branch behind a driver check is not a coverage gap,
        // and nothing written against SQLite can close it; the question to ask is whether the
        // engine suites cover the behavior.
        if (DB::connection()->getDriverName() === 'pgsql') {
            self::execute('DROP FUNCTION IF EXISTS '.self::qualify(self::FUNCTION).'();');
            self::execute('DROP FUNCTION IF EXISTS '.self::qualify(self::DELETE_FUNCTION).'();');
        }
    }

    /**
     * A table, trigger or function name carrying the connection's table prefix.
     *
     * The prefix is a first-class Laravel setting that `Schema::create()` and `DB::table()` apply
     * transparently — so hand-written DDL that names a table literally is the one place it goes
     * missing, and it goes missing loudly: on a prefixed connection the CREATE TRIGGER below would
     * name a table the schema does not have, and the migration chain would stop half-applied.
     *
     * Trigger and function names take the prefix too. They live in the schema namespace rather
     * than under the table, so two prefixed installations sharing one database would otherwise
     * collide on the second install rather than on the first tampering attempt.
     *
     * The prefix is configuration, not input, but so is a column name — and protectedColumns()
     * already refuses to take that on trust. Same rule here: anything that is not a bare
     * identifier fragment stops before it reaches a statement.
     */
    private static function qualify(string $name): string
    {
        $prefix = DB::connection()->getTablePrefix();

        if (preg_match('/^\w*$/', $prefix) !== 1) {
            throw new RuntimeException(
                "refusing to build the legal_documents guards for an unexpected table prefix: {$prefix}"
            );
        }

        return $prefix.$name;
    }

    /**
     * The ONE place hand-built DDL from this class reaches the connection.
     *
     * Nothing here can be a literal string: the MySQL and SQLite arms enumerate the live column
     * list because neither engine can diff a row minus a set of keys inside a trigger, and every
     * arm carries a table prefix that is only known at runtime. Funnelling them through a single
     * method keeps the static exemption for `unprepared()`'s literal-string requirement to one
     * line, pointing at the place the validation lives, instead of a file-wide waiver that would
     * also cover a statement assembled from something less careful.
     *
     * What may reach this method: names from {@see self::qualify()} and columns from
     * {@see self::protectedColumns()}, both of which refuse anything that is not a bare SQL
     * identifier, plus the class's own constants.
     */
    private static function execute(string $sql): void
    {
        DB::unprepared($sql);
    }

    /**
     * The proof columns present now — every column except the operational allowlist and the
     * columns the database DERIVES for itself.
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
            self::generatedColumns(),
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

    /**
     * The columns the database computes for itself, which are never proof.
     *
     * A generated column cannot be written directly — the engine rejects the attempt — so
     * freezing it protects nothing. It does the opposite: MySQL has no partial index, so
     * migration 000025 enforces the one-active-version invariant with a STORED column derived
     * from `is_active`, and `is_active` is deliberately mutable. Enumerated into the trigger,
     * that column turns every legitimate activation into a frozen-proof violation, and a fresh
     * MySQL installation can never publish a second version at all.
     *
     * Nothing is given up by the exclusion, and that is what makes it safe rather than
     * convenient: a derived value cannot be tampered with on its own, only through its inputs,
     * and every input of that expression — `key`, `locale`, `tenant_id` — stays protected here.
     * The one input that is not, `is_active`, is on the allowlist because activation is the
     * change this table exists to permit.
     *
     * The default-deny shape of {@see self::protectedColumns()} is kept intact: a column added
     * later is still protected without an edit. Only a column the database itself computes is
     * subtracted, and only because the catalog says so.
     *
     * @return list<string>
     */
    private static function generatedColumns(): array
    {
        // The schema BUILDER rather than the Schema facade. The facade's `@method` annotation
        // erases the return to a bare `array`, so every name read out of it is `mixed` and the
        // filter below would be an assumption dressed as a check. The builder declares
        // `list<array{name: string, …, generation: array{…}|null}>`, which is the shape this
        // method depends on.
        $columns = DB::connection()->getSchemaBuilder()->getColumns('legal_documents');

        return array_values(array_map(
            static fn (array $column): string => $column['name'],
            array_filter($columns, static fn (array $column): bool => $column['generation'] !== null),
        ));
    }

    private static function installPostgres(): void
    {
        // A dynamic allowlist: strip the four mutable keys from both row images and compare what
        // is left. A proof column added later is covered with no edit here (fails closed).
        $strip = implode('', array_map(
            static fn (string $column): string => " - '{$column}'",
            LegalDocument::MUTABLE_AFTER_PUBLISH,
        ));

        $documents = self::qualify('legal_documents');
        $consents = self::qualify('legal_consents');
        $updateTrigger = self::qualify(self::TRIGGER);
        $updateFunction = self::qualify(self::FUNCTION);
        $deleteTrigger = self::qualify(self::DELETE_TRIGGER);
        $deleteFunction = self::qualify(self::DELETE_FUNCTION);
        $deleteMessage = self::DELETE_MESSAGE;

        self::execute(<<<SQL
            CREATE OR REPLACE FUNCTION {$updateFunction}() RETURNS trigger AS \$\$
            BEGIN
                IF to_jsonb(NEW){$strip} IS DISTINCT FROM to_jsonb(OLD){$strip} THEN
                    RAISE EXCEPTION 'legal_documents rows are frozen proof — publish a new version instead (EDPB 05/2020 Rz. 108)';
                END IF;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER {$updateTrigger}
                BEFORE UPDATE ON {$documents}
                FOR EACH ROW EXECUTE FUNCTION {$updateFunction}();

            CREATE OR REPLACE FUNCTION {$deleteFunction}() RETURNS trigger AS \$\$
            BEGIN
                IF EXISTS (SELECT 1 FROM {$consents} WHERE document_id = OLD.id) THEN
                    RAISE EXCEPTION '{$deleteMessage}';
                END IF;
                RETURN OLD;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER {$deleteTrigger}
                BEFORE DELETE ON {$documents}
                FOR EACH ROW EXECUTE FUNCTION {$deleteFunction}();
            SQL);
    }

    private static function installMysql(): void
    {
        // Enumerated distinctness over every protected column, compared as BYTES.
        //
        // `<=>` alone was the defect: it is null-safe EQUALITY, and equality on a string column
        // follows that column's collation. Every collation Laravel configures by default
        // (utf8mb4_unicode_ci, utf8mb4_0900_ai_ci) is case- AND accent-insensitive and PAD SPACE,
        // so a raw `UPDATE … SET ui_wording = UPPER(ui_wording)` read as "unchanged" and walked
        // through a trigger whose whole claim is that the row is frozen. PostgreSQL compares
        // `to_jsonb` images and SQLite compares BINARY, so MySQL was the one engine of the three
        // where a case, accent or trailing-space edit of `title`, `ui_wording` or `version`
        // survived. CAST(… AS BINARY) restores byte equality while keeping `<=>`'s NULL handling,
        // and it is the non-deprecated spelling of the old `BINARY x` operator.
        $sameCondition = implode(' AND ', array_map(
            static fn (string $column): string => "CAST(NEW.`{$column}` AS BINARY) <=> CAST(OLD.`{$column}` AS BINARY)",
            self::protectedColumns(),
        ));

        $documents = self::qualify('legal_documents');
        $consents = self::qualify('legal_consents');
        $updateTrigger = self::qualify(self::TRIGGER);
        $deleteTrigger = self::qualify(self::DELETE_TRIGGER);
        $deleteMessage = self::DELETE_MESSAGE;

        self::execute(<<<SQL
            CREATE TRIGGER {$updateTrigger}
                BEFORE UPDATE ON {$documents}
                FOR EACH ROW
            BEGIN
                IF NOT ({$sameCondition}) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'legal_documents rows are frozen proof — publish a new version instead (EDPB 05/2020 Rz. 108)';
                END IF;
            END;
            SQL);

        // A separate statement: MySQL takes one CREATE TRIGGER per call, and reading
        // `legal_consents` here is allowed because that table is not the one the firing statement
        // is modifying — the restriction is on the table under the trigger, not on every table.
        self::execute(<<<SQL
            CREATE TRIGGER {$deleteTrigger}
                BEFORE DELETE ON {$documents}
                FOR EACH ROW
            BEGIN
                IF EXISTS (SELECT 1 FROM {$consents} WHERE document_id = OLD.id) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = '{$deleteMessage}';
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

        $documents = self::qualify('legal_documents');
        $consents = self::qualify('legal_consents');
        $updateTrigger = self::qualify(self::TRIGGER);
        $deleteTrigger = self::qualify(self::DELETE_TRIGGER);
        $deleteMessage = self::DELETE_MESSAGE;

        self::execute(<<<SQL
            CREATE TRIGGER {$updateTrigger}
                BEFORE UPDATE ON {$documents}
                FOR EACH ROW
                WHEN ({$changedCondition})
            BEGIN
                SELECT RAISE(ABORT, 'legal_documents rows are frozen proof — publish a new version instead (EDPB 05/2020 Rz. 108)');
            END;
            SQL);

        self::execute(<<<SQL
            CREATE TRIGGER {$deleteTrigger}
                BEFORE DELETE ON {$documents}
                FOR EACH ROW
                WHEN (EXISTS (SELECT 1 FROM {$consents} WHERE {$consents}.document_id = OLD.id))
            BEGIN
                SELECT RAISE(ABORT, '{$deleteMessage}');
            END;
            SQL);
    }
}
