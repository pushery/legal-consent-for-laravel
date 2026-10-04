<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Pushery\LegalConsent\Support\ChangeSetFreezeGuard;
use Pushery\SQLens\Attributes\NoSqlOnDriver;

/**
 * Let PostgreSQL delete a draft change description.
 *
 * The trigger function that freezes a published change set, and the one for its items, ended with
 * `RETURN NEW` for UPDATE and DELETE alike. In a BEFORE DELETE row trigger `NEW` is null, and a
 * BEFORE row trigger that returns null skips the row, so on PostgreSQL no delete of a draft did
 * anything: `ChangeItems::discard()` reported success and left the draft in place, and saving a
 * draft a second time failed on the unique position of its items, because the old ones were never
 * removed. MySQL and SQLite were not affected.
 *
 * Both functions are replaced in place where they exist, and the triggers stay bound to them. A
 * fresh installation already gets the corrected body from 000016 and 000017.
 */
return new
#[NoSqlOnDriver('mysql', reason: 'the trigger functions it replaces exist on PostgreSQL only; MySQL was not affected')]
class extends Migration
{
    public function up(): void
    {
        ChangeSetFreezeGuard::replaceFunctions();
    }

    /**
     * Nothing to put back: the body this replaced let no draft be deleted on PostgreSQL, and a
     * rollback that restored it would restore that, not a state anything relied on.
     */
    #[NoSqlOnDriver('pgsql', reason: 'the replaced body let no draft be deleted, and nothing relied on that')]
    public function down(): void
    {
        // The functions stay as up() left them.
    }
};
