<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pushery\LegalConsent\Support\ProofColumnGuard;

/**
 * Make `legal_documents` identity mean the same thing on all three engines: compare `key`,
 * `locale`, `tenant_id` and `version` as BYTES on MySQL, as SQLite and PostgreSQL already do.
 *
 * WHAT WAS MEASURED. On SQLite, a document published under `key = 'terms'` is not found by
 * `where('key', 'TERMS')` (`exact=1 upper=0`), and publishing a second document under `'TERMS'`
 * succeeds — two rows, two identities. PostgreSQL behaves the same way under the collations its
 * usual locales carry. MySQL is the outlier in BOTH directions at once, because every collation
 * Laravel configures by default (`utf8mb4_unicode_ci`, `utf8mb4_0900_ai_ci`) is case- AND
 * accent-insensitive: the mis-cased lookup silently resolves, and the second publish is refused by
 * `legal_documents_key_locale_version_tenant_id_unique`. The MySQL half is read from the schema and
 * the documented collation semantics, not executed — no MySQL server was contacted for this
 * change.
 *
 * WHY THIS IS FIXED RATHER THAN DOCUMENTED. A lookup that answers differently per engine is a
 * caller error handled two ways, and prose could cover it. The unique index is not: it decides
 * whether two rows are the same legal document. An application developed on SQLite can publish
 * `terms` and `TERMS` as separate documents and then fail to migrate its own data onto MySQL,
 * where those rows cannot coexist — and it finds out in production, on the table that is meant to
 * be the record. A constraint that means one thing in development and another in production is not
 * a constraint.
 *
 * WHY ONLY `legal_documents`. This is the table the package resolves identity against:
 * `activeDocumentIn()` reads it, the partial one-active index guards it, and every ledger row
 * copies its canonical `key` from the row it found (`'document_key' => $document->key`) rather
 * than from the caller's string — so no proof row can hold a differently-cased variant of a key
 * that exists. `legal_change_sets` and `legal_drafts` carry identity indexes too, but their rows
 * are authored against a document that has to exist first, so a divergence there cannot produce
 * two competing legal texts. Every column moved here is one more piece of MySQL DDL, and widening
 * the change buys nothing for the property being protected.
 *
 * The charset follows the collation: `utf8mb4_bin` implies utf8mb4, so a `MODIFY` naming only the
 * collation is self-consistent, and a table still on utf8mb3 is converted for these four columns.
 * At 64, 10, 64 and 20 characters the index key stays far below InnoDB's 3072-byte limit either
 * way.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->isMysql()) {
            return;
        }

        // The 000011 note: a migration that changes a COLUMN on this table re-installs the guard
        // afterwards. MySQL keeps its triggers across a MODIFY, so this is belt and braces here —
        // but the rule is worth following without exception, because the engine where it is NOT
        // belt and braces fails silently.
        ProofColumnGuard::whileDisarmed(function (): void {
            Schema::table('legal_documents', function (Blueprint $table): void {
                $table->string('key', 64)->collation('utf8mb4_bin')->change();
                $table->string('locale', 10)->collation('utf8mb4_bin')->change();
                $table->string('tenant_id', 64)->default('')->collation('utf8mb4_bin')->change();
                $table->string('version', 20)->collation('utf8mb4_bin')->change();
            });
        });
    }

    /**
     * Hand the four columns back to the TABLE's default collation.
     *
     * Naming a collation here would be a guess: the original was whatever the consumer's
     * connection configured, `utf8mb4_unicode_ci` on one installation and `utf8mb4_0900_ai_ci` on
     * the next. A `MODIFY` that states neither charset nor collation takes the table default,
     * which is the same value the column had before this migration ran — an inverse that does not
     * need to know what it is undoing.
     */
    public function down(): void
    {
        if (! $this->isMysql()) {
            return;
        }

        ProofColumnGuard::whileDisarmed(function (): void {
            Schema::table('legal_documents', function (Blueprint $table): void {
                $table->string('key', 64)->change();
                $table->string('locale', 10)->change();
                $table->string('tenant_id', 64)->default('')->change();
                $table->string('version', 20)->change();
            });
        });
    }

    private function isMysql(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }
};
