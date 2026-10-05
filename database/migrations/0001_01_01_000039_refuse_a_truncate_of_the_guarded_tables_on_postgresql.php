<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Pushery\LegalConsent\Support\ChangeSetFreezeGuard;
use Pushery\LegalConsent\Support\ProofColumnGuard;
use Pushery\SQLens\Attributes\NoSqlOnDriver;

/**
 * Refuse a TRUNCATE on PostgreSQL wherever the delete guards refuse a DELETE.
 *
 * A TRUNCATE removes every row of a table without firing a row trigger, so the BEFORE DELETE
 * guards on `legal_documents`, `legal_change_sets` and `legal_change_items` do not see it, and
 * Laravel's `truncate()` issues one there, with CASCADE. Each of the three tables gets a BEFORE
 * TRUNCATE trigger that runs its delete guard's function for the whole table: `legal_documents`
 * refuses while a consent points at one of its versions, and the change tables refuse while they
 * hold a published description.
 *
 * A fresh installation receives the triggers from 000011, 000016 and 000017, which install the
 * guards; this installs them again where those migrations ran before. MySQL runs no trigger on
 * TRUNCATE TABLE, and on SQLite Laravel's `truncate()` is a DELETE the row triggers answer, so this
 * does nothing on either.
 */
return new
#[NoSqlOnDriver('mysql', reason: 'MySQL runs no trigger on TRUNCATE TABLE, so there is no guard to install')]
class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        ProofColumnGuard::install();
        ChangeSetFreezeGuard::install();
        ChangeSetFreezeGuard::installItems();
    }

    /**
     * The guards stay as up() left them, for the reason 000021 gives: each guard is installed and
     * removed as a whole, and taking only the TRUNCATE triggers off would need a second copy of
     * their SQL. A fresh installation carries the triggers from 000011, 000016 and 000017, so a
     * rollback that removed them here would leave a state that migrating never produces.
     */
    #[NoSqlOnDriver('pgsql', reason: 'the guards stay installed; the migrations that create them take them off')]
    public function down(): void
    {
        // The guards stay as up() left them.
    }
};
