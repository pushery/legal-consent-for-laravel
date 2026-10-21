<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pushery\LegalConsent\Support\ProofColumnGuard;
use Pushery\LegalConsent\Support\TableLookup;

/**
 * `locale` and `version` get the width their own formats need.
 *
 * They were `varchar(10)` and `varchar(20)`, and both are too narrow for values that are perfectly
 * well-formed. `ca-ES-valencia` is a valid BCP 47 tag at 14 characters; `1.0.0-alpha.1+build.123`
 * is valid SemVer at 23. The tight one is `locale`, and it is tight enough to hit ordinary
 * traffic rather than exotica: `zh-Hant-TW`, `zh-Hans-CN` and `sr-Latn-RS` are each exactly 10, so
 * a consumer serving Traditional Chinese sits on the limit and any variant subtag goes over it.
 *
 * And the failure is invisible where people develop. SQLite ignores a `varchar` length
 * completely: `ca-ES-valencia` goes into a `VARCHAR(10)` column and comes back at 14 characters.
 * MySQL 8.4 in strict mode refuses the same row outright with `1406 Data too long`. So a
 * development database accepts the value, and the deployment is where it breaks.
 * That is the same divergence class migration 000022 closed for collation.
 *
 * The new widths are 35 and 64. RFC 5646 sets no hard limit, but 35 covers every tag the IANA
 * registry can compose in practice; SemVer likewise has no limit, and 64 leaves room without
 * making an index expensive. The widest index over these columns is `legal_documents`'s
 * `(key, locale, version, tenant_id)`, which grows from 158 to 227 characters — 908 bytes under
 * utf8mb4, well inside InnoDB's 3072-byte key limit.
 *
 * SQLite is skipped on purpose, and skipping it is what makes this migration safe. There is
 * nothing to widen there — the length is not enforced — and a `change()` would be Laravel's
 * twelve-step table rebuild, which drops the triggers `ProofColumnGuard` installs and brings the
 * partial one-active index back full (the warning migration 000025 carries in its own words). A
 * rebuild that gains nothing is a rebuild not worth its risk.
 *
 * On MySQL and PostgreSQL the change is an in-place ALTER: triggers survive it, and MySQL accepts
 * it even on `legal_documents.locale`, which a STORED generated column concatenates and a unique
 * index covers.
 *
 * The create migrations are left at 10 and 20 on purpose, and that is the opposite of what it
 * looks like. Widening them too would mean a fresh install never holds the narrow column — tidier
 * to read, and it puts the same number in six files plus this one, with nothing holding them in
 * lockstep. The next person to change a width would have to find all seven, and the one they miss
 * is silent: an `ALTER` to a width the table already has succeeds and changes nothing, so a stale
 * CREATE and a correct CREATE look identical afterwards.
 *
 * So this migration is the single place that states the current width, and a fresh install reaches
 * it the same way an existing one does: create narrow, widen here. The one ALTER a fresh install
 * pays for is the price of there being one number.
 */
return new class extends Migration
{
    /** @var list<array{table: string, columns: array<string, string>}> */
    private const array WIDENED = [
        ['table' => 'legal_documents', 'columns' => ['locale' => 'locale', 'version' => 'version']],
        ['table' => 'legal_consents', 'columns' => ['locale' => 'locale', 'version' => 'document_version']],
        ['table' => 'legal_notices', 'columns' => ['locale' => 'locale', 'version' => 'document_version']],
        ['table' => 'legal_drafts', 'columns' => ['locale' => 'locale', 'version' => 'version']],
        ['table' => 'legal_change_sets', 'columns' => ['locale' => 'locale', 'version' => 'version']],
    ];

    public function up(): void
    {
        $this->resize(35, 64);
    }

    /**
     * Narrowing back is only safe while every stored value still fits, which is the honest inverse:
     * a rollback after a consumer has written `ca-ES-valencia` would have to destroy that value to
     * succeed. It is left to fail loudly instead — the engine refuses the ALTER and says which row
     * is in the way, which is a better answer than a migration that silently truncates a locale.
     */
    public function down(): void
    {
        $this->resize(10, 20);
    }

    private function resize(int $locale, int $version): void
    {
        // The driver check sits at the alter rather than around this whole method, so the two
        // "not there" branches below answer on every engine, SQLite included, while SQLite still
        // receives no ALTER.
        //
        // `legal_documents` is the proof-guarded table, so its columns move with the guard down,
        // exactly as migration 000015 and 000022 do it. The guard fails closed against a
        // migration-time write, and following the rule without exception is the point: the engine
        // where it is not belt and braces is the one that fails silently.
        ProofColumnGuard::whileDisarmed(function () use ($locale, $version): void {
            foreach (self::WIDENED as $target) {
                if (! TableLookup::exists($target['table'])) {
                    continue;
                }

                Schema::table($target['table'], function (Blueprint $table) use ($target, $locale, $version): void {
                    $this->column($table, $target, 'locale', $locale);
                    $this->column($table, $target, 'version', $version);
                });
            }
        });
    }

    /**
     * @param  array{table: string, columns: array<string, string>}  $target
     */
    private function column(Blueprint $table, array $target, string $role, int $width): void
    {
        $name = $target['columns'][$role];

        if (! Schema::hasColumn(TableLookup::qualified($target['table']), $name)) {
            return;
        }

        // SQLite ignores a `varchar` length entirely, so there is nothing here to widen — and a
        // `change()` would be Laravel's twelve-step table rebuild, which drops the proof triggers
        // and brings the partial one-active index back full. See the header.
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        $column = $table->string($name, $width);

        // The collation has to be restated on MySQL or the change undoes 000022. A `MODIFY`
        // that names neither charset nor collation takes the TABLE default, and 000022 put
        // `legal_documents`.`locale` and `version` on `utf8mb4_bin` precisely so the three engines
        // agree on what "the same document" means. Widening them back into a case-insensitive
        // collation would reintroduce the divergence that migration closed, on the same columns,
        // and nothing about the width would look wrong afterwards.
        if ($target['table'] === 'legal_documents' && DB::connection()->getDriverName() === 'mysql') {
            $column->collation('utf8mb4_bin');
        }

        // The two columns that are not plain NOT NULL keep what they had; `change()` states the
        // whole definition, so an omitted default or nullability is a silent change of its own.
        if ($target['table'] === 'legal_drafts' && $role === 'version') {
            $column->nullable();
        }

        if ($target['table'] === 'legal_change_sets' && $role === 'version') {
            $column->default('');
        }

        $column->change();
    }
};
