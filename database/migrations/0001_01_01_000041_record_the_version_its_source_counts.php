<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pushery\LegalConsent\Support\ProofColumnGuard;

/**
 * The version a source counts itself, beside the SemVer version, on a published version and on
 * every consent given to it.
 *
 * Another system can count the revisions of a text with a whole number of its own and keep the
 * archive of which revision a customer read. The SemVer version is what the re-consent gate reads;
 * this is what that system calls the same text, kept word for word so a consent can be matched
 * to its archive without translating one numbering into the other. Both columns are nullable,
 * because a source that counts nothing of its own leaves them empty, and every row written before
 * this migration has no such version.
 *
 * `legal_documents.source_version` is a proof column like the rest of a published row, so it is
 * added while the guard is disarmed and frozen when it is armed again; the consent column is added
 * in the same call, so no trigger of the guard is re-read while either table changes. On a consent
 * the column enters the record hash only when it carries a value, so every chain written before
 * keeps verifying as it is.
 *
 * On MySQL both columns compare as bytes, as the identity of a document does since migration
 * 000033, so a source version is found by what it says and not by a case- or accent-insensitive
 * likeness of it. SQLite and PostgreSQL compare them that way already.
 */
return new class extends Migration
{
    private const string BYTES = 'utf8mb4_0900_bin';

    public function up(): void
    {
        ProofColumnGuard::whileDisarmed(function (): void {
            Schema::table('legal_documents', function (Blueprint $table): void {
                $this->sourceVersion($table, 'source_version');
            });

            Schema::table('legal_consents', function (Blueprint $table): void {
                $this->sourceVersion($table, 'document_source_version');
            });
        });
    }

    public function down(): void
    {
        ProofColumnGuard::whileDisarmed(function (): void {
            Schema::table('legal_consents', function (Blueprint $table): void {
                $table->dropColumn('document_source_version');
            });

            Schema::table('legal_documents', function (Blueprint $table): void {
                $table->dropColumn('source_version');
            });
        });
    }

    private function sourceVersion(Blueprint $table, string $name): void
    {
        $column = $table->string($name, 64)->nullable();

        if (DB::connection()->getDriverName() === 'mysql') {
            $column->collation(self::BYTES);
        }
    }
};
