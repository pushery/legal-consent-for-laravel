<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Support\Facades\DB;

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
     * `DB::unprepared()` takes a literal string — a requirement that is itself a guard, and not one
     * worth weakening for a constant. So this IS a second copy of the enum's backing value, and the
     * drift it invites is the dangerous kind: rename the enum case and the triggers keep running,
     * comparing against a value no row can hold. They would freeze nothing and still report success,
     * because a dead trigger and a live one look identical from the outside.
     *
     * `ChangeSetFreezeGuardLockstepTest` holds the two together.
     */
    public const string FROZEN_STATE = 'published';

    public static function install(): void
    {
        self::drop();

        match (DB::connection()->getDriverName()) {
            'pgsql' => self::installPostgres('legal_change_sets', self::SET_TRIGGER_UPDATE, self::SET_TRIGGER_DELETE),
            'mysql' => self::installMysql('legal_change_sets', self::SET_TRIGGER_UPDATE, self::SET_TRIGGER_DELETE),
            default => null, // SQLite and anything else are covered by the model hook alone
        };
    }

    public static function installItems(): void
    {
        self::dropItems();

        match (DB::connection()->getDriverName()) {
            'pgsql' => self::installPostgres('legal_change_items', self::ITEM_TRIGGER_UPDATE, self::ITEM_TRIGGER_DELETE),
            'mysql' => self::installMysql('legal_change_items', self::ITEM_TRIGGER_UPDATE, self::ITEM_TRIGGER_DELETE),
            default => null,
        };
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
     * @param  literal-string  $table
     * @param  literal-string  $updateTrigger
     * @param  literal-string  $deleteTrigger
     */
    private static function installPostgres(string $table, string $updateTrigger, string $deleteTrigger): void
    {
        $function = $table.'_guard_frozen';
        $message = self::MESSAGE;

        // OLD.state, never NEW.state: the question is whether the row WAS frozen, and reading the
        // incoming value would let an update that also rewrites `state` walk straight past the guard.
        DB::unprepared(<<<SQL
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

        DB::unprepared(<<<SQL
            CREATE TRIGGER {$updateTrigger}
                BEFORE UPDATE ON {$table}
                FOR EACH ROW
                BEGIN
                    IF OLD.state = 'published' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}';
                    END IF;
                END
            SQL);

        DB::unprepared(<<<SQL
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
     * @param  literal-string  $table
     * @param  literal-string  $updateTrigger
     * @param  literal-string  $deleteTrigger
     * @param  literal-string  $function
     */
    private static function dropFor(string $table, string $updateTrigger, string $deleteTrigger, string $function): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared("DROP TRIGGER IF EXISTS {$updateTrigger} ON {$table}");
            DB::unprepared("DROP TRIGGER IF EXISTS {$deleteTrigger} ON {$table}");
            DB::unprepared("DROP FUNCTION IF EXISTS {$function}()");
        }

        if ($driver === 'mysql') {
            DB::unprepared("DROP TRIGGER IF EXISTS {$updateTrigger}");
            DB::unprepared("DROP TRIGGER IF EXISTS {$deleteTrigger}");
        }
    }
}
