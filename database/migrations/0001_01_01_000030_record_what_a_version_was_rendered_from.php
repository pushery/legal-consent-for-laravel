<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\LegalConsent\Support\ProofColumnGuard;

/**
 * A published version records the SOURCE it was rendered from, and the renderer that rendered it.
 *
 * `content_hash` alone cannot say WHY the HTML of a document changed, and the difference decides
 * what the operator has to do. Registering CommonMark's TableExtension turned a paragraph of pipe
 * characters into a real table in every text that held one; `legal-consent:check-drift` could only
 * report "source differs from published v1.0.0 — publish a new version and set its materiality",
 * for texts nobody had edited. And where a real edit and the renderer change coincided, the report
 * could not say which part was which, so a re-publish would have frozen the real change in silence.
 *
 * `source_hash` is taken over the source text before rendering, `render_fingerprint` over the
 * markdown options, the extension list, the sanitizer allowlists and the installed CommonMark
 * version. Together they let the checker separate a changed TEXT from a changed PRESENTATION, and
 * they let `legal-consent:rerender` prove a text is untouched before it re-freezes it.
 *
 * ⚠️ BOTH ARE NULLABLE, AND NO BACKFILL IS POSSIBLE — that is the honest shape rather than a
 * shortcut. A row published before this migration was rendered from a source whose bytes nobody
 * kept; the only value that could be written now is a hash of TODAY's source, which would claim
 * the text was unchanged at publish time without anything having checked. So a legacy row says
 * "unknown", the checker reports exactly that, and the first ordinary publish fills it in.
 *
 * Both columns are proof: {@see ProofColumnGuard} derives its protected set from the catalog minus
 * the operational allowlist, so they are frozen after publish without an edit there. The trigger
 * enumerates columns on MySQL and SQLite, which is why the schema change runs inside the guard —
 * it is re-installed over the new shape, and without that the two new columns would be writable on
 * a frozen row while everything looked normal.
 */
return new class extends Migration
{
    public function up(): void
    {
        ProofColumnGuard::whileDisarmed(function (): void {
            Schema::table('legal_documents', function (Blueprint $table): void {
                $table->char('source_hash', 64)->nullable();
                $table->char('render_fingerprint', 64)->nullable();
            });
        });
    }

    public function down(): void
    {
        ProofColumnGuard::whileDisarmed(function (): void {
            Schema::table('legal_documents', function (Blueprint $table): void {
                $table->dropColumn(['source_hash', 'render_fingerprint']);
            });
        });
    }
};
