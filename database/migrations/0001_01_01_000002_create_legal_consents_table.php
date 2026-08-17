<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only consent ledger. One row per acceptance / acknowledgement /
 * re-acceptance / withdrawal, carrying DENORMALIZED snapshots so each row proves
 * itself even if the document or the subject is later gone (Art. 5(2), Art. 7(1),
 * EDPB 05/2020 Rz. 108). No updated_at, no UPDATE in the app layer; DELETE stays
 * allowed for retention/anonymization only.
 *
 * Immutability is enforced portably: the LegalConsent model blocks updates in
 * every engine, and Postgres + MySQL additionally get a BEFORE UPDATE trigger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_consents', function (Blueprint $table): void {
            $table->id();

            // Polymorphic + nullable subject — proof survives account deletion
            // (Art. 17(3)(b)/(e)); subject_token is a stable pseudonym that also
            // survives anonymization.
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->uuid('subject_token')->nullable();

            // Optional multi-tenancy (config `tenancy`). '' = no/shared tenant.
            $table->string('tenant_id', 64)->default('');

            $table->foreignId('document_id')->nullable()->constrained('legal_documents')->nullOnDelete();

            // Denormalized snapshots — the row carries the evidence itself.
            $table->string('document_key', 64);
            $table->string('document_type', 32);
            $table->string('document_version', 20);
            $table->unsignedInteger('document_major_version');
            $table->char('content_hash', 64);
            $table->string('locale', 10);
            $table->text('ui_wording_snapshot');

            $table->string('action', 20);   // ConsentAction::value
            $table->string('method', 32);   // ConsentMethod::value
            $table->string('source', 32)->nullable();

            // Server-side context captured at the moment of consent.
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('request_id', 64)->nullable();

            // Optional tamper-evidence hash chain (config: tamper_evidence, default off).
            $table->char('prev_record_hash', 64)->nullable();

            // UTC instant consent was obtained (EDPB Rz. 108). No updated_at.
            $table->timestampTz('accepted_at');
            $table->timestampTz('created_at')->nullable();

            $table->index(['subject_type', 'subject_id', 'document_key', 'accepted_at'], 'legal_consents_subject_doc_time_idx');
            $table->index(['document_key', 'document_major_version', 'action']);
            $table->index('subject_token');
        });

        $this->installAppendOnlyTrigger();
    }

    public function down(): void
    {
        $this->dropAppendOnlyTrigger();

        Schema::dropIfExists('legal_consents');
    }

    /**
     * Defense-in-depth: refuse UPDATE at the database level on the engines that
     * support it. The app-layer model guard covers SQLite and everything else.
     */
    private function installAppendOnlyTrigger(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION legal_consents_block_update() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'legal_consents is append-only (Art. 5(2) DSGVO Rechenschaftspflicht)';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER legal_consents_no_update
                    BEFORE UPDATE ON legal_consents
                    FOR EACH ROW EXECUTE FUNCTION legal_consents_block_update();
                SQL);
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::unprepared(<<<'SQL_WRAP'
            CREATE TRIGGER legal_consents_no_update
                BEFORE UPDATE ON legal_consents
                FOR EACH ROW
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'legal_consents is append-only (Art. 5(2) GDPR accountability)';
            SQL_WRAP);
        }
    }

    private function dropAppendOnlyTrigger(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS legal_consents_no_update ON legal_consents;');
            DB::unprepared('DROP FUNCTION IF EXISTS legal_consents_block_update();');
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::unprepared('DROP TRIGGER IF EXISTS legal_consents_no_update;');
        }
    }
};
