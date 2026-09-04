<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pushery\LegalConsent\Support\ProofColumnGuard;

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
 *
 * The rebuild runs inside `ProofColumnGuard::whileDisarmed()`, and that is not caution — it is the
 * difference between a schema change and a destroyed ledger table. Dropping a foreign key on
 * SQLite means rebuilding `legal_consents`: copy into a replacement, DROP the original, rename the
 * replacement into place. `legal_documents` carries a DELETE guard that names `legal_consents`,
 * SQLite re-parses every trigger in the schema at that rename, and a trigger whose referenced
 * table is missing at that instant is a hard error — raised after the original has already gone.
 *
 * ⚠️ ON SQLITE THIS REBUILDS THE TABLE, AND A REBUILD DOES NOT CARRY YOUR OWN TRIGGERS ACROSS.
 * SQLite cannot drop a foreign key in place, so the grammar creates a temp table, copies the rows,
 * drops the original and renames — reconstructing columns, indexes, primary key and foreign keys
 * from BlueprintState, and nothing else. A trigger a CONSUMER added to `legal_consents` is silently
 * gone afterwards, and so is a CHECK constraint or a partial-index predicate.
 *
 * The package's own append-only triggers are unaffected, and not by luck: migration 000002 installs
 * them only on pgsql and mysql. What is at risk is exclusively something you wrote yourself.
 *
 * SQLite also does not wrap migrations in a transaction (its grammar leaves `$transactions` false,
 * unlike Postgres), so a failure part-way through leaves the rebuild half-done rather than rolled
 * back. Take a file copy before migrating a SQLite database you care about, and re-create your own
 * triggers afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->isSqlite()) {
            return;
        }

        ProofColumnGuard::whileDisarmed(function (): void {
            Schema::table('legal_consents', function (Blueprint $table): void {
                $table->dropForeign(['document_id']);
            });
        });
    }

    public function down(): void
    {
        if (! $this->isSqlite()) {
            return;
        }

        ProofColumnGuard::whileDisarmed(function (): void {
            Schema::table('legal_consents', function (Blueprint $table): void {
                $table->foreign('document_id')->references('id')->on('legal_documents')->nullOnDelete();
            });
        });
    }

    private function isSqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }
};
