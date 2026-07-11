<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable, versioned legal texts. Each published version is a FROZEN row: the
 * exact sanitized text a user was shown, its content hash, and the dates that
 * drive change detection and enforcement. Never update a published row — publish a
 * new version instead (that is what makes the ledger legally provable, EDPB
 * 05/2020 Rz. 108).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_documents', function (Blueprint $table): void {
            $table->id();

            // Identity of the document across versions.
            $table->string('key', 64);                 // 'terms' | 'privacy' | 'newsletter' | ...
            $table->string('type', 32);                // DocumentType::value
            $table->boolean('requires_explicit_optin')->default(false);
            $table->string('locale', 10);

            // Optional multi-tenancy (config `tenancy`). '' = no/shared tenant, so the
            // unique/one-active constraints below stay intact when tenancy is unused (all
            // rows share ''); with tenancy on, each tenant gets its own active version.
            $table->string('tenant_id', 64)->default('');

            // Versioning: SemVer string + split parts. major_version drives re-consent.
            $table->string('version', 20);
            $table->unsignedInteger('major_version');
            $table->unsignedInteger('minor_version')->default(0);
            $table->unsignedInteger('patch_version')->default(0);

            // The frozen, provable content of this version.
            $table->string('title')->default('');      // heading shown with the text
            $table->string('content_format', 16)->default('markdown'); // 'markdown' | 'html'
            $table->text('content');                   // sanitized HTML snapshot (the proof)
            $table->char('content_hash', 64);          // sha256(canonicalize(sanitized html))
            $table->text('ui_wording');                // exact acceptance sentence, this version+locale

            // Where the text came from (audit only; the row owns the content).
            $table->string('source_driver', 32)->default('database'); // 'markdown' | 'database' | 'cms'
            $table->string('source_reference')->nullable();

            // Change classification (set by a human at publish; the hash never judges this).
            $table->boolean('requires_reconsent')->default(false);
            $table->string('change_summary')->nullable();

            // Exactly one active version per (key, locale). Enforced portably in the
            // app layer; on Postgres additionally by a partial unique index below.
            $table->boolean('is_active')->default(false);

            // The delayed-notice timeline: published_at < announce_from < enforce_from.
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('announce_from')->nullable();  // when subjects are notified
            $table->timestampTz('enforce_from')->nullable();   // when the middleware enforces
            $table->timestampTz('notified_at')->nullable();    // dispatch-notices watermark

            $table->timestampsTz();

            $table->unique(['key', 'locale', 'version', 'tenant_id']);
            $table->index(['key', 'locale', 'is_active', 'enforce_from']);
            $table->index(['key', 'locale', 'major_version']);
            $table->index(['key', 'content_hash']);
        });

        // Postgres can guarantee "one active version per (key, locale)" at the DB
        // level with a partial unique index; MySQL/SQLite rely on the app-layer
        // guard in LegalDocument::activate().
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX legal_documents_one_active_per_key_locale '
                .'ON legal_documents (key, locale, tenant_id) WHERE is_active = true'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_documents');
    }
};
