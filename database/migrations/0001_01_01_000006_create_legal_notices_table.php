<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only proof that a CHANGE NOTICE was delivered to a subject on a durable medium.
 * The consent ledger proves the document content and the subject's acceptance; this table
 * proves the missing third fact — that the subject was actually informed, when, in what
 * form, and that the notice carried its mandatory content. Required for a disadvantageous
 * or materially-adverse change (durable medium: CJEU C-375/15 BAWAG; accountability:
 * Art. 5(2) DSGVO; mandatory content: § 308 Nr. 5 / § 675g / § 327r / P2B).
 *
 * Immutable like the consent ledger: the LegalNotice model blocks UPDATE in every engine,
 * and Postgres + MySQL additionally get a BEFORE UPDATE trigger. DELETE stays allowed for
 * retention pruning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_notices', function (Blueprint $table): void {
            $table->id();

            // Polymorphic + nullable subject, same shape as legal_consents — the proof
            // survives account deletion; subject_token is the stable pseudonym.
            $table->string('subject_type')->nullable();
            // A STRING key, not an integer one, and 64 rather than 36. The ledger has to hold
            // whatever the consuming app keys its subjects by: an auto-increment id survives as
            // its own decimal text, a UUID is 36 characters, a ULID 26. 64 matches the width this
            // table already gives `document_key`, `request_id` and `tenant_id`, and leaves room
            // for a prefixed or composite key without touching the schema again.
            $table->string('subject_id', 64)->nullable();
            $table->uuid('subject_token')->nullable();

            $table->string('tenant_id', 64)->default('');

            // Deliberately NOT a foreign key. An `ON DELETE SET NULL` is an UPDATE of this row, and
            // this table is append-only: on Postgres the referential action fires the BEFORE UPDATE
            // trigger and aborts the parent DELETE; on MySQL it bypasses the trigger and silently
            // mutates the ledger. The row is self-proving through its denormalized document_key /
            // document_version / document_major_version snapshots, so the constraint buys nothing
            // the immutability guarantee does not already cost.
            $table->unsignedBigInteger('document_id')->nullable();

            // Denormalized snapshot of the version the notice was about. The locale belongs in
            // that snapshot: a version is published per locale, so key + version alone do NOT
            // identify which language the subject was actually served — and document_id, being
            // deliberately FK-free and nullable, may be gone by the time the proof is read.
            $table->string('document_key', 64);
            $table->string('document_version', 20);
            $table->unsignedInteger('document_major_version');
            $table->string('locale', 10);

            $table->string('notice_mode', 24);              // NoticeMode::value
            $table->string('medium', 32);                   // 'email' | 'post' | 'durable_message'

            // The exact rendered message + its hash — the provable content of the notice.
            $table->text('notice_body');
            $table->char('notice_content_hash', 64);

            // Whether the notice carried its mandatory content (the change, the effective
            // date, and — where the right exists — the objection/free-termination right). A
            // false value makes a deficient notice detectable rather than silently "sent".
            $table->boolean('mandatory_content_ok')->default(false);

            $table->timestampTz('sent_at');
            $table->timestampTz('delivered_at')->nullable(); // delivery-confirmation watermark
            $table->timestampTz('created_at')->nullable();

            $table->index(['subject_type', 'subject_id', 'document_key', 'sent_at'], 'legal_notices_subject_doc_time_idx');
            $table->index(['document_id']);
            $table->index('subject_token');
        });

        $this->installAppendOnlyTrigger();
    }

    public function down(): void
    {
        $this->dropAppendOnlyTrigger();

        Schema::dropIfExists('legal_notices');
    }

    /**
     * Defense-in-depth: refuse UPDATE at the database level on the engines that support it.
     * The app-layer model guard covers SQLite and everything else.
     */
    private function installAppendOnlyTrigger(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION legal_notices_block_update() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'legal_notices is append-only (Art. 5(2) DSGVO Rechenschaftspflicht)';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER legal_notices_no_update
                    BEFORE UPDATE ON legal_notices
                    FOR EACH ROW EXECUTE FUNCTION legal_notices_block_update();
                SQL);
        }

        if ($driver === 'mysql') {
            DB::unprepared(<<<'SQL_WRAP'
            CREATE TRIGGER legal_notices_no_update
                BEFORE UPDATE ON legal_notices
                FOR EACH ROW
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'legal_notices is append-only (Art. 5(2) GDPR accountability)';
            SQL_WRAP);
        }
    }

    private function dropAppendOnlyTrigger(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS legal_notices_no_update ON legal_notices;');
            DB::unprepared('DROP FUNCTION IF EXISTS legal_notices_block_update();');
        }

        // `mariadb` on the DROP side only — the engine is refused on install (ProofColumnGuard),
        // but 0.13.0 installed this trigger there and such a database must still be able to shed it.
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::unprepared('DROP TRIGGER IF EXISTS legal_notices_no_update;');
        }
    }
};
