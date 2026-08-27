<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Makes a PUBLISHED change set — and its items — physically un-editable, while leaving the draft
 * fully mutable.
 *
 * This is a CONDITIONAL freeze, and that is the difference from `ProofColumnGuard`.
 * `legal_documents` needs a column allowlist because four of its columns must stay writable
 * forever (the activation flag, the two sweep watermarks). A change set has no such split: it is
 * entirely editable until it is published and entirely frozen afterwards. So the condition is the
 * row's own state, not a list of columns — the layering of `legal_notices`, which blocks
 * unconditionally, with one predicate added.
 *
 * DELETE is blocked as well as UPDATE, which `legal_notices` does not do. A notice row holds
 * personal data and must remain prunable under a retention rule; a change set describes the change
 * rather than a subject, so nothing ages out of it, and the only reason to delete one would be to
 * make a published description disappear.
 *
 * It ships on ALL THREE engines. SQLite used to be left to the model hook alone, on the reasoning
 * that the app layer is the portable baseline — but the hook cannot see the paths this guard is
 * for, and the sibling migration says so in as many words: a `saveQuietly()`, a raw
 * `DB::table()->update()`, a mass `Model::query()->delete()` and a referential cascade all reach
 * the row without firing a single model event. Measured on 2026-08-27, a published change set on
 * SQLite could be rewritten and deleted through every one of those, while the same call refused on
 * PostgreSQL and MySQL — so "physically un-editable" was true on two engines of three, which is
 * the shape of claim that is worse than no claim.
 *
 * Lives in `src/` rather than in the migration for the reason ProofColumnGuard documents: SQLite
 * rebuilds a table to alter a column, and a rebuild keeps indexes but DROPS TRIGGERS. Any later
 * migration touching these tables must be able to re-install the guard, and silently not doing so
 * is exactly the failure that has no symptom.
 */
final class ChangeSetFreezeGuard
{
    public const string SET_TRIGGER_UPDATE = 'legal_change_sets_no_frozen_update';

    public const string SET_TRIGGER_DELETE = 'legal_change_sets_no_frozen_delete';

    public const string ITEM_TRIGGER_UPDATE = 'legal_change_items_no_frozen_update';

    public const string ITEM_TRIGGER_DELETE = 'legal_change_items_no_frozen_delete';

    private const string MESSAGE = 'a published legal change description is frozen: it is the record of what a subject was told';

    /**
     * The stored value the triggers below test `OLD.state` against.
     *
     * It is spelled out here rather than interpolated from {@see ChangeSetState::Published} because
     * the trigger bodies below are literal SQL — a requirement that is itself a guard, and not one
     * worth weakening for a constant. So this IS a second copy of the enum's backing value, and the
     * drift it invites is the dangerous kind: rename the enum case and the triggers keep running,
     * comparing against a value no row can hold. They would freeze nothing and still report success,
     * because a dead trigger and a live one look identical from the outside.
     *
     * A lockstep test holds the two together.
     */
    public const string FROZEN_STATE = 'published';

    public static function install(): void
    {
        $driver = self::assertSupportedEngine();

        self::drop();

        match ($driver) {
            'pgsql' => self::installPostgres('legal_change_sets', self::SET_TRIGGER_UPDATE, self::SET_TRIGGER_DELETE),
            'mysql' => self::installMysql('legal_change_sets', self::SET_TRIGGER_UPDATE, self::SET_TRIGGER_DELETE),
            'sqlite' => self::installSqlite('legal_change_sets', self::SET_TRIGGER_UPDATE, self::SET_TRIGGER_DELETE),
        };
    }

    public static function installItems(): void
    {
        $driver = self::assertSupportedEngine();

        self::dropItems();

        match ($driver) {
            'pgsql' => self::installPostgres('legal_change_items', self::ITEM_TRIGGER_UPDATE, self::ITEM_TRIGGER_DELETE),
            'mysql' => self::installMysql('legal_change_items', self::ITEM_TRIGGER_UPDATE, self::ITEM_TRIGGER_DELETE),
            'sqlite' => self::installSqlite('legal_change_items', self::ITEM_TRIGGER_UPDATE, self::ITEM_TRIGGER_DELETE),
        };
    }

    /**
     * The engine check, run BEFORE anything is dropped — which is why it is its own method rather
     * than the first lines of install().
     *
     * Both matches above used to carry `default => null`, commented "an engine this package does
     * not claim to protect". {@see ProofColumnGuard::assertSupportedEngine()} spells out why that
     * is the wrong arm, and the argument transfers unchanged: a consumer on a fourth engine would
     * get migrations that SUCCEED, a `legal_change_sets` table with no trigger on it, and no signal
     * anywhere — a published change description silently rewritable on the one table whose purpose
     * is to record what a subject was told. A migration that stops is recoverable; a frozen row
     * that only looks frozen is not.
     *
     * The migration chain could never reach that arm anyway: 000011 arms {@see ProofColumnGuard}
     * and refuses a fourth engine there, long before 000016 creates the change tables. So the only
     * way in was a direct call to this public method, and the only thing a test could have asserted
     * at it is that the guard installs nothing and says nothing — the behavior the sentence below
     * refuses to promise.
     *
     * The message names the change tables rather than deferring to ProofColumnGuard's, which talks
     * about `legal_documents`: an operator who sees a refusal should be told which table stopped
     * the chain.
     *
     * The return type is the literal union rather than `string`, and that is load-bearing — it is
     * what lets the matches above stay exhaustive without a `default` arm at all.
     *
     * @return 'mysql'|'pgsql'|'sqlite' the driver name, already proven to be one this guard can protect
     */
    private static function assertSupportedEngine(): string
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['pgsql', 'mysql', 'sqlite'], true)) {
            throw new RuntimeException(
                "a published legal change description cannot be frozen on the '{$driver}' driver: the freeze triggers are written for PostgreSQL, MySQL and SQLite. Recording what a subject was told without them would leave every published row rewritable, so this stops rather than continuing quietly."
            );
        }

        return $driver;
    }

    public static function drop(): void
    {
        self::dropFor('legal_change_sets', self::SET_TRIGGER_UPDATE, self::SET_TRIGGER_DELETE, 'legal_change_sets_guard_frozen');
    }

    public static function dropItems(): void
    {
        self::dropFor('legal_change_items', self::ITEM_TRIGGER_UPDATE, self::ITEM_TRIGGER_DELETE, 'legal_change_items_guard_frozen');
    }

    /**
     * A table, trigger or function name carrying the connection's table prefix.
     *
     * See {@see ProofColumnGuard} for the full reasoning: a prefix
     * is a supported Laravel setting that the schema builder applies transparently, so literal
     * hand-written DDL is the one place it goes missing — and it goes missing by naming a table
     * the schema does not have, half-way through the migration chain.
     *
     * @param  literal-string  $name
     */
    private static function qualify(string $name): string
    {
        $prefix = DB::connection()->getTablePrefix();

        if (preg_match('/^\w*$/', $prefix) !== 1) {
            throw new RuntimeException(
                "refusing to build the change-set freeze guard for an unexpected table prefix: {$prefix}"
            );
        }

        return $prefix.$name;
    }

    /**
     * The ONE place hand-built DDL from this class reaches the connection.
     *
     * Every statement below is assembled rather than literal, because a table prefix and the
     * trigger names derived from it are only known at runtime. Funnelling them through a single
     * method keeps that fact auditable: the static exemption for `unprepared()`'s literal-string
     * requirement is one line pointing here, next to the validation that earns it, instead of a
     * file-wide waiver that would also cover a statement built from something less careful.
     *
     * What may reach this method: names produced by {@see self::qualify()}, which refuses anything
     * that is not a bare identifier fragment, and the class's own constants. Nothing else.
     */
    private static function execute(string $sql): void
    {
        DB::unprepared($sql);
    }

    /**
     * @param  literal-string  $table
     * @param  literal-string  $updateTrigger
     * @param  literal-string  $deleteTrigger
     */
    private static function installPostgres(string $table, string $updateTrigger, string $deleteTrigger): void
    {
        $function = self::qualify($table.'_guard_frozen');
        $message = self::MESSAGE;
        $table = self::qualify($table);
        $updateTrigger = self::qualify($updateTrigger);
        $deleteTrigger = self::qualify($deleteTrigger);

        // OLD.state, never NEW.state: the question is whether the row WAS frozen, and reading the
        // incoming value would let an update that also rewrites `state` walk straight past the guard.
        self::execute(<<<SQL
            CREATE OR REPLACE FUNCTION {$function}() RETURNS trigger AS \$\$
            BEGIN
                IF OLD.state = 'published' THEN
                    RAISE EXCEPTION '{$message}';
                END IF;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER {$updateTrigger}
                BEFORE UPDATE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION {$function}();

            CREATE TRIGGER {$deleteTrigger}
                BEFORE DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION {$function}();
            SQL);
    }

    /**
     * @param  literal-string  $table
     * @param  literal-string  $updateTrigger
     * @param  literal-string  $deleteTrigger
     */
    private static function installMysql(string $table, string $updateTrigger, string $deleteTrigger): void
    {
        $message = self::MESSAGE;
        $table = self::qualify($table);
        $updateTrigger = self::qualify($updateTrigger);
        $deleteTrigger = self::qualify($deleteTrigger);

        self::execute(<<<SQL
            CREATE TRIGGER {$updateTrigger}
                BEFORE UPDATE ON {$table}
                FOR EACH ROW
                BEGIN
                    IF OLD.state = 'published' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}';
                    END IF;
                END
            SQL);

        self::execute(<<<SQL
            CREATE TRIGGER {$deleteTrigger}
                BEFORE DELETE ON {$table}
                FOR EACH ROW
                BEGIN
                    IF OLD.state = 'published' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}';
                    END IF;
                END
            SQL);
    }

    /**
     * The SQLite arm — two triggers, no function, the same OLD.state predicate.
     *
     * `RAISE(ABORT, …)` rolls back the statement rather than the transaction, which is what makes
     * the refusal observable as a QueryException in a consumer's request instead of poisoning
     * whatever else that request had already written.
     *
     * The publish transition is unaffected and that is worth stating, because a guard that blocked
     * it would be caught nowhere: `ChangeItemsFreezer` lifts the row from `draft` to `published`,
     * so the row it updates has OLD.state = 'draft' and passes both triggers on the way in. Only
     * the second write to the same row meets a frozen OLD.
     *
     * @param  literal-string  $table
     * @param  literal-string  $updateTrigger
     * @param  literal-string  $deleteTrigger
     */
    private static function installSqlite(string $table, string $updateTrigger, string $deleteTrigger): void
    {
        $message = self::MESSAGE;
        $frozen = self::FROZEN_STATE;
        $table = self::qualify($table);
        $updateTrigger = self::qualify($updateTrigger);
        $deleteTrigger = self::qualify($deleteTrigger);

        self::execute(<<<SQL
            CREATE TRIGGER {$updateTrigger}
                BEFORE UPDATE ON {$table}
                FOR EACH ROW
                WHEN (OLD.state = '{$frozen}')
            BEGIN
                SELECT RAISE(ABORT, '{$message}');
            END;
            SQL);

        self::execute(<<<SQL
            CREATE TRIGGER {$deleteTrigger}
                BEFORE DELETE ON {$table}
                FOR EACH ROW
                WHEN (OLD.state = '{$frozen}')
            BEGIN
                SELECT RAISE(ABORT, '{$message}');
            END;
            SQL);
    }

    /**
     * @param  literal-string  $table
     * @param  literal-string  $updateTrigger
     * @param  literal-string  $deleteTrigger
     * @param  literal-string  $function
     */
    private static function dropFor(string $table, string $updateTrigger, string $deleteTrigger, string $function): void
    {
        $driver = DB::connection()->getDriverName();
        $table = self::qualify($table);
        $updateTrigger = self::qualify($updateTrigger);
        $deleteTrigger = self::qualify($deleteTrigger);
        $function = self::qualify($function);

        if ($driver === 'pgsql') {
            self::execute("DROP TRIGGER IF EXISTS {$updateTrigger} ON {$table}");
            self::execute("DROP TRIGGER IF EXISTS {$deleteTrigger} ON {$table}");
            self::execute("DROP FUNCTION IF EXISTS {$function}()");
        }

        // DROP side names `mariadb` on purpose; see ProofColumnGuard::drop(). The engine is
        // refused on install, but an installation that received these under 0.13.0 must be able to
        // shed them. SQLite is named because it now receives the triggers too, and because a table
        // rebuild takes them off — re-installing has to be able to start from a clean slate.
        if (in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            self::execute("DROP TRIGGER IF EXISTS {$updateTrigger}");
            self::execute("DROP TRIGGER IF EXISTS {$deleteTrigger}");
        }
    }
}
