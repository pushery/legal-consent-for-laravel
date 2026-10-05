<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\LegalConsent\Support\IndexName;

/**
 * An index for the retention sweep that leads with the column the sweep filters on.
 *
 * `legal-consent:prune` selects `where accepted_at < :cutoff` (and `sent_at` on the notice ledger)
 * and pages by `id`. `accepted_at` sits fourth in `legal_consents_subject_doc_time_idx` and
 * `sent_at` fourth in its notice twin, so neither of those indexes can serve the predicate as a
 * prefix.
 *
 * In an append-only ledger `id` and `accepted_at` correlate, so a page with rows to delete is a
 * short primary-key range either way. The page this index is for is the one that finds nothing: a
 * scheduled run often deletes nothing at all, and without the index that page reads the whole
 * table to say so. With it, the rows older than the cutoff are found without reading the others,
 * so while no row is older than the cutoff a run costs a few pages however large the ledger is.
 *
 * Rows older than the cutoff are still each checked for a newer row of the same subject, document
 * and locale, through `legal_consents_affected_subject_idx`, because a row that names its subject
 * goes only once a newer row has superseded it. A subject's current row is never deleted, so once
 * it ages past the cutoff it is checked on every run: the cost of a run grows with the rows older
 * than the cutoff, which in a ledger where most subjects accepted their current version long ago is
 * most of the ledger.
 *
 * `id` is the second column rather than the only other one: the sweep orders and pages by it
 * (`chunkById`), so the pair answers "nothing older than the cutoff above the last id seen" inside
 * the index instead of against the table.
 *
 * Both ledgers get it, because both sweeps have the same shape. The `subject_doc_time` indexes
 * serve a different question, one subject's history, and stay as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_consents', function (Blueprint $table): void {
            $table->index(['accepted_at', 'id'], IndexName::of('legal_consents_retention_idx'));
        });

        Schema::table('legal_notices', function (Blueprint $table): void {
            $table->index(['sent_at', 'id'], IndexName::of('legal_notices_retention_idx'));
        });
    }

    public function down(): void
    {
        Schema::table('legal_consents', function (Blueprint $table): void {
            $table->dropIndex(IndexName::existing('legal_consents', 'legal_consents_retention_idx'));
        });

        Schema::table('legal_notices', function (Blueprint $table): void {
            $table->dropIndex(IndexName::existing('legal_notices', 'legal_notices_retention_idx'));
        });
    }
};
