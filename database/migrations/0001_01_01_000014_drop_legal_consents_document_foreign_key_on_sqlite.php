<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the `legal_consents.document_id` foreign key on SQLite too.
 *
 * 000008 dropped this foreign key on PostgreSQL and MySQL because its `ON DELETE SET NULL` is an
 * UPDATE of an append-only ledger row, but it EXCLUDED SQLite on the now-outdated assumption that
 * the driver cannot drop a foreign key. Laravel 13 drops it via a table rebuild, so the exclusion
 * left SQLite as the one supported engine where deleting a superseded legal_documents row still
 * cascades a silent `document_id = NULL` onto the immutable ledger row — a raw referential write
 * the model's updating() hook never sees, and SQLite carries no BEFORE UPDATE trigger (the app
 * layer is its only guard, and a raw cascade slips straight past it). Any SQLite consumer running
 * with foreign keys enabled (Laravel's default) was exposed.
 *
 * This unifies SQLite with the other two engines: the denormalized proof columns keep every row
 * self-proving, and document_id simply dangles once the document is gone (its index stays — 000009).
 *
 * Guarded to SQLite: PostgreSQL and MySQL already dropped this constraint in 000008, so running the
 * drop there would fail on a missing key.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->isSqlite()) {
            return;
        }

        Schema::table('legal_consents', function (Blueprint $table): void {
            $table->dropForeign(['document_id']);
        });
    }

    public function down(): void
    {
        if (! $this->isSqlite()) {
            return;
        }

        Schema::table('legal_consents', function (Blueprint $table): void {
            $table->foreign('document_id')->references('id')->on('legal_documents')->nullOnDelete();
        });
    }

    private function isSqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }
};
