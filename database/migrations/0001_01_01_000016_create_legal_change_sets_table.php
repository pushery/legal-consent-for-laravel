<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\LegalConsent\Support\ChangeSetFreezeGuard;

/**
 * What a version CHANGED, in the operator's own words, per locale — the content every real-world
 * change notice leads with and this package could not carry.
 *
 * It is a child table rather than a column on `legal_documents` for two reasons that are both
 * about that table's guard. A column there would have to be added to the proof-column allowlist
 * and the trigger re-installed on three engines; and it would be varchar-shaped, which cannot hold
 * a structured delta (a list of added and removed sub-processors is the common case, not a
 * sentence). `legal_documents.change_summary` is exactly that dead column, and it stays untouched.
 *
 * ONE row is mutable: the working draft, marked by `version = ''`. Publishing TRANSITIONS it — the
 * same row gains the version and flips to `published` — rather than copying it, so the frozen text
 * and the authored text cannot drift apart.
 *
 * The empty string, not NULL, is what makes "one draft per key+locale+tenant" enforceable: SQLite
 * and PostgreSQL treat NULLs in a unique index as distinct from each other, so a nullable column
 * would allow any number of concurrent drafts, while MySQL would allow one. A default that means
 * something different per engine is not a default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_change_sets', function (Blueprint $table): void {
            $table->id();

            $table->string('key', 64);
            $table->string('locale', 10);
            $table->string('tenant_id', 64)->default('');

            // '' = the single editable draft; anything else is a frozen, published set.
            $table->string('version', 20)->default('');
            $table->string('state', 16)->default('draft'); // ChangeSetState::value

            // Set at freeze time. `document_content_hash` binds the description to exactly the text
            // it describes, so a later reader can tell whether they belong together.
            //
            // Deliberately NOT a foreign key, for the reason 000006 and 000008 give for the two
            // ledger tables: an `ON DELETE SET NULL` is an UPDATE of this row, and this table
            // carries a BEFORE UPDATE trigger keyed on `state = 'published'`. PostgreSQL runs a
            // referential action through SPI, so the trigger fires and the parent DELETE aborts;
            // MySQL applies it WITHOUT firing the row trigger and silently mutates a row this
            // package calls frozen; SQLite has no trigger to fire and mutates it too. The plain
            // column keeps the relation readable and simply dangles once the document is gone —
            // which is what the SET NULL was reaching for, without the illegal write.
            $table->unsignedBigInteger('document_id')->nullable();
            $table->char('document_content_hash', 64)->nullable();

            // § 327r Abs. 2 Satz 2 Nr. 1 BGB wants the characteristics of the change; WP260 rev.01
            // Rz. 31 separately wants its likely impact. Two obligations, two fields — TEXT, because
            // neither is reliably one line.
            $table->text('headline')->nullable();
            $table->text('impact')->nullable();

            // The source fingerprint when the description was authored. A publish where this no
            // longer matches means the text moved after the summary was written, and the summary
            // may now describe something else.
            $table->string('source_fingerprint', 128)->nullable();

            $table->char('change_hash', 64)->nullable(); // sha256 of the frozen content
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            $table->unique(['key', 'locale', 'tenant_id', 'version'], 'legal_change_sets_identity_unique');
            $table->index(['key', 'locale', 'state']);
            $table->index('document_id');
        });

        ChangeSetFreezeGuard::install();
    }

    public function down(): void
    {
        ChangeSetFreezeGuard::drop();

        Schema::dropIfExists('legal_change_sets');
    }
};
