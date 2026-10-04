<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Build the unique index that holds one active version per document on MySQL, where migration
 * 000025 left only its column.
 *
 * On MySQL, 000025 adds the derived `active_identity` column and then the unique index on it. MySQL
 * does not roll DDL back, so where the index failed because two active versions of one document
 * were stored, the column stayed, and a run after the clean-up saw the column and skipped the index
 * with it. The migration was recorded, and the database went on accepting a second active version.
 * 000025 now checks the two apart; this builds the index for a database that already went through
 * that. Where two active versions of one document are still stored, it fails the way 000025 did,
 * and succeeds on the next run once one of them is retired.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // Named before anything is asked of the table, so a prefix the guard refuses is refused here
        // rather than answered by a lookup of a table that carries it.
        $table = $this->prefixed('legal_documents');
        $index = $this->prefixed('legal_documents_one_active_per_key_locale');

        if (! Schema::hasIndex('legal_documents', $index)) {
            DB::statement("CREATE UNIQUE INDEX {$index} ON {$table} (active_identity)");
        }
    }

    /**
     * Nothing to undo: the index belongs to migration 000025, whose down() drops it with the column.
     */
    public function down(): void {}

    /**
     * A table or index name carrying the connection's table prefix, with the guard migration 000025
     * uses, because the name is interpolated into DDL.
     */
    private function prefixed(string $name): string
    {
        $prefix = DB::connection()->getTablePrefix();

        if (preg_match('/^\w*\z/', $prefix) !== 1) {
            throw new RuntimeException("refusing to build legal_documents DDL for an unexpected table prefix: {$prefix}");
        }

        return $prefix.$name;
    }
};
