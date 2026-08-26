<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the subject was stripped from a proof row under Art. 17, on both ledgers.
 *
 * The erasure seam rewrites a row without its personal columns and re-links the chain behind it.
 * That is a lawful, application-initiated change to append-only evidence, and evidence that
 * changes without a record of the change is the thing this package exists to prevent — so the
 * change records itself. A `subject_id` that is simply null cannot say whether a person was
 * erased or the row never had one (the v1 backfill can write either).
 *
 * NOT a hashed proof field, deliberately. `LedgerHashChain::canonical()` walks a FIXED list of
 * eighteen columns, so a new one is invisible to it and no stored hash moves — which is what lets
 * this ship without re-chaining a single existing row. It is a marker for an operator and for the
 * retention sweep, never a claim the chain vouches for.
 *
 * Adding a column does not rebuild the table on any engine here (only CHANGING one does), so the
 * triggers on both ledgers stay installed — the hazard 000011 and 000018 both had to plan around.
 */
return new class extends Migration
{
    /** The proof tables carrying a polymorphic subject reference. */
    private const array TABLES = ['legal_consents', 'legal_notices'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'subject_erased_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->timestampTz('subject_erased_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'subject_erased_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('subject_erased_at');
            });
        }
    }
};
