<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remove the `legal_consents.document_id` foreign key: its `ON DELETE SET NULL` conflicts with
 * the append-only guarantee.
 *
 * A referential SET NULL is an UPDATE of the ledger row, and the two engines disagree about what
 * that means when a BEFORE UPDATE trigger guards the table: PostgreSQL runs the action through
 * SPI, so the trigger fires and the parent DELETE aborts with "legal_consents is append-only";
 * MySQL applies the action WITHOUT firing the row trigger, so it silently mutates a row the
 * package promises is immutable. Either way, deleting a superseded `legal_documents` row — an
 * ordinary admin/retention action — hits it.
 *
 * The constraint is not load-bearing: every ledger row is self-proving through its denormalized
 * `document_key` / `document_version` / `document_major_version` / `content_hash` snapshots, which
 * is the whole point of the table (Art. 5(2), Art. 7(1), EDPB 05/2020 Rz. 108). The plain column
 * keeps the `document()` relation working (its index is added explicitly in 000009 — dropping the
 * foreign key leaves the column unindexed on PostgreSQL and SQLite); it simply resolves to null
 * once the document is gone — exactly what the SET NULL was reaching for, without the illegal write.
 *
 * Only Postgres and MySQL carry the trigger (and the FK), so only they need the drop.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->supportsForeignKeyDrop()) {
            return;
        }

        Schema::table('legal_consents', function (Blueprint $table): void {
            $table->dropForeign(['document_id']);
        });
    }

    public function down(): void
    {
        if (! $this->supportsForeignKeyDrop()) {
            return;
        }

        Schema::table('legal_consents', function (Blueprint $table): void {
            $table->foreign('document_id')->references('id')->on('legal_documents')->nullOnDelete();
        });
    }

    private function supportsForeignKeyDrop(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql'], true);
    }
};
