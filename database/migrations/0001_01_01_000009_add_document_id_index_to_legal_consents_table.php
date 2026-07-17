<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index `legal_consents.document_id`.
 *
 * The column was created via `foreignId(...)->constrained(...)` (000002), then its foreign key
 * was dropped (000008) because the referential SET NULL fought the append-only guarantee. On
 * PostgreSQL and SQLite a foreign key never carries an implicit index, so after the drop the
 * column is entirely unindexed — yet the sibling migration's docblock still described it as a
 * "plain indexed column". The `document()` relation and any audit lookup by document therefore
 * seq-scan the ledger. This adds the explicit index the column was documented to have.
 *
 * MySQL retains the auto-created index when a foreign key is dropped, so it is already covered;
 * adding the explicit named index there is a harmless no-op-in-effect kept for schema clarity and
 * migrate-from-zero symmetry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_consents', function (Blueprint $table): void {
            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::table('legal_consents', function (Blueprint $table): void {
            $table->dropIndex(['document_id']);
        });
    }
};
