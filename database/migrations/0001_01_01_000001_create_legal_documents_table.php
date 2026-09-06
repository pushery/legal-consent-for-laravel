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
            $table->string('source_driver', 32)->default('markdown'); // the configured source name that produced this row
            $table->string('source_reference')->nullable();

            // Change classification (set by a human at publish; the hash never judges this).
            $table->boolean('requires_reconsent')->default(false);
            $table->string('change_summary')->nullable();

            // Exactly one active version per (key, locale). Enforced portably in the app layer,
            // by the partial unique index below on Postgres, and — since migration 000025 — by the
            // database on SQLite and MySQL too.
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

        // Postgres guarantees "one active version per (key, locale)" at the DB level with a
        // partial unique index. SQLite takes the identical index and MySQL the generated-column
        // equivalent — both in migration 000025, which is where to look for the other two arms.
        //
        // Hand-written SQL, so the table prefix has to be applied by hand — the schema builder
        // does it everywhere else, which is precisely why a literal name here goes unnoticed until
        // an application with a configured prefix runs `migrate` and gets a missing table
        // half-way through the chain. The INDEX name takes the prefix too: an index lives in the
        // schema namespace rather than under its table, so two prefixed installations sharing one
        // database would otherwise collide on the second install.
        if (DB::connection()->getDriverName() === 'pgsql') {
            $table = $this->prefixed('legal_documents');
            $index = $this->prefixed('legal_documents_one_active_per_key_locale');

            DB::statement(
                "CREATE UNIQUE INDEX {$index} "
                ."ON {$table} (key, locale, tenant_id) WHERE is_active = true"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_documents');
    }

    /**
     * A table or index name carrying the connection's table prefix.
     *
     * The prefix is configuration rather than input, but a name that is not a bare identifier
     * fragment would be interpolated into DDL, and "it cannot be hostile" is an assumption, not a
     * guard. Refuse before a single character of it reaches a statement.
     */
    private function prefixed(string $name): string
    {
        $prefix = DB::connection()->getTablePrefix();

        if (preg_match('/^\w*$/', $prefix) !== 1) {
            throw new RuntimeException("refusing to build legal_documents DDL for an unexpected table prefix: {$prefix}");
        }

        return $prefix.$name;
    }
};
