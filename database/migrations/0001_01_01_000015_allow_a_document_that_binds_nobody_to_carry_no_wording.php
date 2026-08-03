<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pushery\LegalConsent\Support\ProofColumnGuard;

/**
 * `ui_wording` is the exact acceptance sentence a subject clicked, and it is proof: it is not
 * in `MUTABLE_AFTER_PUBLISH`, the BEFORE UPDATE trigger rejects changing it, and it is copied
 * verbatim into every ledger row and folded into the hash chain.
 *
 * An `informational` document — an Impressum (§ 5 DDG), a cookie policy, an accessibility
 * statement — asks the reader for nothing, so there is no sentence it could honestly carry.
 * While the column was NOT NULL there was no way to say that: the render pipeline fell through
 * to `wording.default` and froze "Ich habe die Bedingungen gelesen und akzeptiere sie." into a
 * page that binds nobody, permanently, in the one column the package builds its evidentiary
 * weight on.
 *
 * NULL rather than an empty string, deliberately. Both satisfy "no sentence", but the empty
 * string is also what a mis-published consent-bearing document would look like — and the
 * pipeline spends a whole branch making sure that never happens. NULL says "this class has no
 * acceptance sentence"; '' would say "this one's sentence is blank", which is a different and
 * much worse claim about a proof column.
 */
return new class extends Migration
{
    public function up(): void
    {
        // BEFORE the column changes, not after. The guard below refuses an engine it cannot
        // protect, and the MySQL family commits DDL implicitly — so a refusal raised after the
        // ALTER leaves the column already nullable with no row in `migrations`, and every re-run
        // dies at the same place. Asking first costs a refused operator one message instead of a
        // half-migrated table. MariaDB and SQL Server reach this: Laravel returns the configured
        // driver name verbatim, so neither is ever seen as `mysql`.
        ProofColumnGuard::assertSupportedEngine();

        Schema::table('legal_documents', function (Blueprint $table): void {
            $table->text('ui_wording')->nullable()->change();
        });

        // ⚠️ NOT optional, and not defensive coding. SQLite cannot alter a column in place, so
        // the change above is Laravel's twelve-step rebuild: create a new table, copy, drop,
        // rename. Indexes survive that. TRIGGERS DO NOT — so without this line the proof guard
        // installed by 000011 is silently gone, on the one table whose entire purpose is being
        // un-editable. Measured here: `DocumentImmutabilityTest` went red across all 30 proof
        // columns the moment this migration was added, and green again with this line.
        //
        // It is also what keeps the MySQL and SQLite arms correct, independently of the rebuild:
        // those two ENUMERATE the protected columns at install time, so a re-install is what
        // teaches them about a column whose definition just changed.
        ProofColumnGuard::install();
    }

    public function down(): void
    {
        // A rollback has to put back a value the NOT NULL can live with, and there is exactly
        // one that is not a fresh lie: the empty string. Any real sentence written here would
        // be this migration inventing an acceptance nobody gave — the defect it exists to undo.
        //
        // This runs BEFORE the column changes back, or the ALTER fails on the very rows the
        // feature created.
        // The proof trigger refuses this UPDATE — it fails closed against a migration-time write
        // exactly as it does against runtime tampering — so it comes off first and goes back on
        // after, which is also what the rebuild below requires.
        ProofColumnGuard::drop();

        DB::table('legal_documents')->whereNull('ui_wording')->update(['ui_wording' => '']);

        Schema::table('legal_documents', function (Blueprint $table): void {
            $table->text('ui_wording')->nullable(false)->change();
        });

        ProofColumnGuard::install();
    }
};
