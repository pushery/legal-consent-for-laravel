<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pushery\LegalConsent\Models\LegalDocument;

/**
 * Make a published `legal_documents` row physically un-editable — the immutability the table's
 * own docblock promised ("Never update a published row") but never enforced. A published version
 * is frozen proof: the exact sanitized text a subject was shown and the hash the ledger snapshots
 * (EDPB 05/2020 Rz. 108). Correcting a legal text is a NEW version, never an in-place edit.
 *
 * A BEFORE UPDATE trigger with an ALLOWLIST: only `is_active` (activation), `updated_at`,
 * `notified_at` (the dispatch sweep) and `objection_closed_at` (the objection sweep) may change
 * after publish; a change to anything else raises. This is the LAST migration on purpose — it runs
 * after 000005's `notice_mode` backfill (an UPDATE the trigger would otherwise abort) and never
 * fires on existing rows at creation.
 *
 * The model's `updating()` hook is the sibling layer for a normal `save()`, but a trigger is
 * required, not optional: both post-insert sweeps write via `saveQuietly()` (which bypasses model
 * events) and a raw `DB::table()->update()` / `psql` never reaches a model — the trigger is the
 * only guard those paths hit. It ships on ALL THREE engines (unlike the append-only siblings,
 * which block every update via the hook and only add a PG/MySQL trigger): here four columns are
 * legitimately mutable, so a column allowlist has no portable hook-only form and SQLite needs its
 * own trigger for the saveQuietly()/raw paths.
 *
 * On PostgreSQL the guard is a true allowlist (`to_jsonb(NEW) - <allowed>` compared to OLD), so a
 * proof column added by a future migration is protected automatically — it fails CLOSED. MySQL and
 * SQLite cannot diff a row minus columns inside a trigger, so they enumerate every protected column
 * present when this migration runs; a future proof column would slip past them (fails OPEN), which
 * `DocumentImmutabilityTest` catches by iterating the live column list against the allowlist.
 *
 * NOTE for any FUTURE migration that backfills or rewrites a protected column: it must DROP this
 * trigger, run the data change, and re-CREATE it — the PG guard fails closed against a migration-time
 * UPDATE exactly as it does against runtime tampering (documented in UPGRADE.md).
 */
return new class extends Migration
{
    private const string TRIGGER = 'legal_documents_no_proof_update';

    private const string FUNCTION = 'legal_documents_guard_proof';

    public function up(): void
    {
        match (DB::connection()->getDriverName()) {
            'pgsql' => $this->installPostgres(),
            'mysql' => $this->installMysql(),
            'sqlite' => $this->installSqlite(),
            default => null,
        };
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS '.self::TRIGGER.' ON legal_documents;');
            DB::unprepared('DROP FUNCTION IF EXISTS '.self::FUNCTION.'();');

            return;
        }

        if ($driver === 'mysql' || $driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS '.self::TRIGGER.';');
        }
    }

    /** The proof columns present now — every column except the operational allowlist. */
    private function protectedColumns(): array
    {
        return array_values(array_diff(
            Schema::getColumnListing('legal_documents'),
            LegalDocument::MUTABLE_AFTER_PUBLISH,
        ));
    }

    private function installPostgres(): void
    {
        // A dynamic allowlist: strip the four mutable keys from both row images and compare what
        // is left. A proof column added later is covered with no edit here (fails closed).
        $strip = implode('', array_map(
            static fn (string $column): string => " - '{$column}'",
            LegalDocument::MUTABLE_AFTER_PUBLISH,
        ));

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION legal_documents_guard_proof() RETURNS trigger AS \$\$
            BEGIN
                IF to_jsonb(NEW){$strip} IS DISTINCT FROM to_jsonb(OLD){$strip} THEN
                    RAISE EXCEPTION 'legal_documents rows are frozen proof — publish a new version instead (EDPB 05/2020 Rz. 108)';
                END IF;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER legal_documents_no_proof_update
                BEFORE UPDATE ON legal_documents
                FOR EACH ROW EXECUTE FUNCTION legal_documents_guard_proof();
            SQL);
    }

    private function installMysql(): void
    {
        // Enumerated null-safe equality over every protected column: if any differs, SIGNAL.
        $sameCondition = implode(' AND ', array_map(
            static fn (string $column): string => "NEW.`{$column}` <=> OLD.`{$column}`",
            $this->protectedColumns(),
        ));

        DB::unprepared(<<<SQL
            CREATE TRIGGER legal_documents_no_proof_update
                BEFORE UPDATE ON legal_documents
                FOR EACH ROW
            BEGIN
                IF NOT ({$sameCondition}) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'legal_documents rows are frozen proof — publish a new version instead (EDPB 05/2020 Rz. 108)';
                END IF;
            END;
            SQL);
    }

    private function installSqlite(): void
    {
        // `IS NOT` is SQLite's null-safe distinctness; RAISE(ABORT) rolls back only the statement.
        $changedCondition = implode(' OR ', array_map(
            static fn (string $column): string => "NEW.\"{$column}\" IS NOT OLD.\"{$column}\"",
            $this->protectedColumns(),
        ));

        DB::unprepared(<<<SQL
            CREATE TRIGGER legal_documents_no_proof_update
                BEFORE UPDATE ON legal_documents
                FOR EACH ROW
                WHEN ({$changedCondition})
            BEGIN
                SELECT RAISE(ABORT, 'legal_documents rows are frozen proof — publish a new version instead (EDPB 05/2020 Rz. 108)');
            END;
            SQL);
    }
};
