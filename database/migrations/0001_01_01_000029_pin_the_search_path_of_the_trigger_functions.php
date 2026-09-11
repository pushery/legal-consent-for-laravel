<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pin the search_path of the six trigger functions this package installs on PostgreSQL.
 *
 * A function without one resolves its names through the search_path of whoever calls it, and
 * SQLens reports every such function (`PGLS.functionSearchPathMutable`, severity medium) in every
 * consumer's audit. All six run as SECURITY INVOKER, so the exposure is small; the finding in
 * each consumer's release report is not.
 *
 * `FROM CURRENT` rather than a literal path: it stores the search_path this migration runs under,
 * which is the one the functions were created in and resolve their names through today. A literal
 * `public` would break an install whose tables live in another schema, and an empty path would
 * break `legal_documents_guard_delete`, the one body that names a table (`legal_consents`).
 *
 * Existing installs get it here, and a fresh one gets it here too, after the migrations that
 * create the functions. The two guards that can create their functions again carry the same
 * clause, so a re-install does not lose it.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const array FUNCTIONS = [
        'legal_consents_block_update',
        'legal_notices_block_update',
        'legal_documents_guard_proof',
        'legal_documents_guard_delete',
        'legal_change_sets_guard_frozen',
        'legal_change_items_guard_frozen',
    ];

    public function up(): void
    {
        $this->alter('SET search_path FROM CURRENT');
    }

    public function down(): void
    {
        $this->alter('RESET search_path');
    }

    private function alter(string $clause): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::FUNCTIONS as $function) {
            $name = $this->prefixed($function);

            // Only what exists. A consumer may have dropped a guard on purpose, and an ALTER on a
            // missing function would stop the whole migration chain over it.
            if (data_get(DB::selectOne('SELECT to_regprocedure(?) AS oid', [$name.'()']), 'oid') === null) {
                continue;
            }

            // One statement, so it goes through statement() and is prepared. The migrations that
            // create these functions needed unprepared() for their `$$` bodies; an ALTER has none.
            DB::statement("ALTER FUNCTION {$name}() {$clause}");
        }
    }

    /**
     * A function name carrying the connection's table prefix.
     *
     * The prefix is configuration rather than input, but it is interpolated into DDL either way,
     * and "it cannot be hostile" is an assumption rather than a guard. Refuse anything that is not
     * a bare identifier fragment before a character of it reaches a statement.
     */
    private function prefixed(string $name): string
    {
        $prefix = DB::connection()->getTablePrefix();

        if (preg_match('/^\w*$/', $prefix) !== 1) {
            throw new RuntimeException("refusing to pin trigger function search paths for an unexpected table prefix: {$prefix}");
        }

        return $prefix.$name;
    }
};
