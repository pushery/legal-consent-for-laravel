<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The retention sweep gets an index that LEADS with the column it filters on.
 *
 * `legal-consent:prune` selects `where accepted_at < :cutoff` (and `sent_at` on the notice ledger)
 * and pages by `id`. No index led with either column: `accepted_at` sat fourth in
 * `legal_consents_subject_doc_time_idx` and `sent_at` fourth in its notice twin, so neither was
 * reachable as a prefix and the predicate was evaluated against the table.
 *
 * ⚠️ THE DELETING PAGES WERE NEVER THE PROBLEM, AND THAT IS WHY THIS LOOKED FINE. In an append-only
 * ledger `id` and `accepted_at` correlate, so a page that has rows to delete is a cheap primary-key
 * range scan — measured on PostgreSQL 18 over 200 000 rows: `Index Scan using legal_consents_pkey`,
 * 35 shared buffers for 1 000 rows.
 *
 * The expensive page is the LAST one, the one that finds nothing and ends the sweep: a parallel
 * scan over the whole table, 5 339 shared buffers for 0 rows. And in normal operation a scheduled
 * run deletes nothing at all — so that page is not the tail of the cost, it is the ENTIRE cost of
 * every run, and it grows with the ledger, which is exactly where retention starts to matter.
 *
 * `id` is the second column rather than the only other one: the sweep orders and pages by it
 * (`chunkById`), so the pair answers "nothing older than the cutoff above the last id I saw"
 * inside the index instead of against the table.
 *
 * Both ledgers get it, because both sweeps have the same shape. Nothing about the existing
 * `subject_doc_time` indexes changes — they serve a different question (one subject's history) and
 * remain the right index for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_consents', function (Blueprint $table): void {
            $table->index(['accepted_at', 'id'], 'legal_consents_retention_idx');
        });

        Schema::table('legal_notices', function (Blueprint $table): void {
            $table->index(['sent_at', 'id'], 'legal_notices_retention_idx');
        });
    }

    public function down(): void
    {
        Schema::table('legal_consents', function (Blueprint $table): void {
            $table->dropIndex('legal_consents_retention_idx');
        });

        Schema::table('legal_notices', function (Blueprint $table): void {
            $table->dropIndex('legal_notices_retention_idx');
        });
    }
};
