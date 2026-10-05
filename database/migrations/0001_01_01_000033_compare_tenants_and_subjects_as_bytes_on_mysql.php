<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pushery\LegalConsent\Support\ProofColumnGuard;
use Pushery\SQLens\Attributes\NoSqlOnDriver;

/**
 * Compare tenants and subjects as bytes on MySQL, as SQLite and PostgreSQL already do.
 *
 * Laravel's MySQL connection defaults to `utf8mb4_unicode_ci`, which is case- and
 * accent-insensitive and pads trailing spaces. Every `tenant_id` except the one on
 * `legal_documents`, and every `subject_id` and `subject_token`, compared that way: two tenants
 * `cafe` and `café`, or `Acme` and `acme`, saw each other's consents, notices, drafts and change
 * descriptions, and two subjects keyed `aB3x` and `Ab3x`, or `5` and `5 `, were one subject at the
 * gate, in the history export and in an erasure. String subject keys are supported since
 * migration 000018, and `tenant_id` is whatever the consumer's resolver returns.
 *
 * `utf8mb4_0900_bin` rather than the `utf8mb4_bin` migration 000022 used: both compare case and
 * accents apart, but `utf8mb4_bin` still pads, so `acme` and `acme ` were equal there as well. The
 * identity columns of `legal_documents` and the generated `active_identity` move with the rest,
 * so every column one of these is compared with carries the same collation. A column compared with
 * a `_ci` one of the same character set is compared under the binary collation, which is how
 * `legal_consents.document_key` meets `legal_documents.key`.
 *
 * Nothing that was apart comes together: a no-pad binary collation tells more values apart than
 * either one it replaces, so no unique index gains a duplicate.
 */
return new
#[NoSqlOnDriver('pgsql', reason: 'PostgreSQL already compares these columns as bytes; only MySQL needs the binary collation')]
#[NoSqlOnDriver('sqlite', reason: 'SQLite already compares these columns as bytes; only MySQL needs the binary collation')]
class extends Migration
{
    private const string BYTES = 'utf8mb4_0900_bin';

    /**
     * The tenant and subject columns, by table, with the definition each has today.
     *
     * @var array<string, array<string, 'subject'|'token'|'tenant'|'required'>>
     */
    private const array COLUMNS = [
        'legal_consents' => ['subject_id' => 'subject', 'subject_token' => 'token', 'tenant_id' => 'tenant'],
        'legal_notices' => ['subject_id' => 'subject', 'subject_token' => 'token', 'tenant_id' => 'tenant'],
        'legal_notice_attempts' => ['subject_id' => 'required'],
        'legal_drafts' => ['tenant_id' => 'tenant'],
        'legal_change_sets' => ['tenant_id' => 'tenant'],
    ];

    public function up(): void
    {
        if (! $this->isMysql()) {
            return;
        }

        $prefix = $this->prefix();

        ProofColumnGuard::whileDisarmed(function () use ($prefix): void {
            $this->ledgerColumns(self::BYTES);
            $this->documentIdentity(self::BYTES, $prefix);
        });
    }

    /**
     * The ledger columns go back to the table's own collation, as 000022's inverse does, and the
     * identity of `legal_documents` back to the `utf8mb4_bin` 000022 gave it.
     */
    public function down(): void
    {
        if (! $this->isMysql()) {
            return;
        }

        $prefix = $this->prefix();

        ProofColumnGuard::whileDisarmed(function () use ($prefix): void {
            $this->ledgerColumns(null);
            $this->documentIdentity('utf8mb4_bin', $prefix);
        });
    }

    private function ledgerColumns(?string $collation): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns, $collation): void {
                foreach ($columns as $name => $shape) {
                    // `change()` states the whole definition, so the width, the nullability and the
                    // default are restated exactly as they are.
                    $column = match ($shape) {
                        'subject' => $blueprint->string($name, 64)->nullable(),
                        'token' => $blueprint->uuid($name)->nullable(),
                        'tenant' => $blueprint->string($name, 64)->default(''),
                        'required' => $blueprint->string($name, 64),
                    };

                    if ($collation !== null) {
                        $column->collation($collation);
                    }

                    $column->change();
                }
            });
        }
    }

    private function documentIdentity(string $collation, string $prefix): void
    {
        // The widths 000028 set: `locale` 35 and `version` 64.
        Schema::table('legal_documents', function (Blueprint $table) use ($collation): void {
            $table->string('key', 64)->collation($collation)->change();
            $table->string('locale', 35)->collation($collation)->change();
            $table->string('tenant_id', 64)->default('')->collation($collation)->change();
            $table->string('version', 64)->collation($collation)->change();
        });

        // The derived column of the one-active index (000025) states its own collation, and a
        // stored generated column is modified with its expression restated.
        if (Schema::hasColumn('legal_documents', 'active_identity')) {
            DB::statement(
                "ALTER TABLE {$prefix}legal_documents MODIFY COLUMN active_identity VARCHAR(600) COLLATE {$collation} "
                .'GENERATED ALWAYS AS (IF(is_active, CONCAT(`key`, 0x1f, locale, 0x1f, tenant_id), NULL)) STORED'
            );
        }
    }

    /**
     * The table prefix, refused before the first statement when it is not a bare identifier
     * fragment, because it is interpolated into the DDL below and MySQL does not roll a half-run
     * migration back.
     */
    private function prefix(): string
    {
        $prefix = DB::connection()->getTablePrefix();

        if (preg_match('/^\w*\z/', $prefix) !== 1) {
            throw new RuntimeException("refusing to build legal_documents DDL for an unexpected table prefix: {$prefix}");
        }

        return $prefix;
    }

    private function isMysql(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }
};
