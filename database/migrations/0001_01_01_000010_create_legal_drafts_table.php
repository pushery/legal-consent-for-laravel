<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The editable INPUT to publishing — the admin-maintained working copy of a legal text.
 *
 * Kept strictly apart from `legal_documents`, which stays 100% package-owned, append-only OUTPUT
 * and is never edited in an admin screen. That split is the whole design: a draft is mutable
 * working state, a published row is frozen proof. `LegalDocumentPublisher` freezes a draft into a
 * new `legal_documents` version, and the public page renders that frozen row — so the page and
 * the ledger are one text because they are one column of one row.
 *
 * `body` is ALWAYS `RenderPipeline` output (sanitized HTML), never a raw editor buffer: the same
 * sanitizer whose output the ledger hashes runs before storage, so there is exactly one allowlist
 * and the stored draft is already the bytes that will be frozen.
 *
 * Only used when a document declares `'source' => 'drafts'`; a Markdown-sourced consumer never
 * touches this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_drafts', function (Blueprint $table): void {
            $table->id();

            $table->string('key', 64);
            $table->string('locale', 10);

            // Optional multi-tenancy, mirroring legal_documents. '' = no/shared tenant.
            $table->string('tenant_id', 64)->default('');

            // Always RenderPipeline output — never a raw editor buffer.
            $table->text('body');
            $table->char('content_hash', 64);

            // Staleness (non-source locales only): the source-locale content_hash this text was
            // translated from or last confirmed against. NULL on the source row — a text is never
            // stale against itself.
            $table->char('source_hash', 64)->nullable();

            // The version the NEXT release will carry, read from the source-locale row for every
            // locale so locales can never disagree on a version.
            $table->string('version', 20)->nullable();

            $table->string('review_state', 16)->default('draft');   // ReviewState
            $table->string('origin', 16)->default('authored');      // DraftOrigin

            // Monotonic; backs fingerprint(). NOT updated_at: a second-granularity stamp collides
            // on two edits within one second and would serve a stale cached render.
            $table->unsignedBigInteger('revision')->default(1);

            $table->timestampsTz();

            $table->unique(['key', 'locale', 'tenant_id']);
            $table->index(['key', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_drafts');
    }
};
