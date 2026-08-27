<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index `legal_consents (subject_token, id)` for the ledger walk's keyset seek, and drop the
 * single-column `subject_token` index it replaces.
 *
 * `legal-consent:verify-ledger` streams the chained rows grouped by subject in id order, paging
 * with a strict `(subject_token, id) > (lastToken, lastId)` seek — the sort key and the seek key
 * are the same pair, which is what makes the walk resumable. An index on `subject_token` alone
 * orders rows by only the first half of it.
 *
 * THE DROP IS NOT TIDINESS, AND IT IS THE HALF THAT MAKES THIS MIGRATION DO ANYTHING. Adding the
 * pair while the single-column index stays changes nothing on any engine: the planner prefers the
 * narrower index and patches up the second half of the sort key itself. Measured on PostgreSQL
 * 18.4 over 100 subjects with 200 chained rows each, seeking from the middle of one subject's
 * chain:
 *
 *   single-column index only   Index Scan using legal_consents_subject_token_index
 *                              -> Incremental Sort (Sort Key: subject_token, id)
 *                              Rows Removed by Filter: 101
 *   both indexes present       byte-identical plan, identical cost — the pair is never chosen
 *   the pair alone             Index Scan using legal_consents_token_id_idx
 *                              Index Cond includes the whole ROW(subject_token, id) > ROW(...)
 *                              no Sort node, Rows Removed by Filter: 0
 *
 * The `Rows Removed by Filter: 101` is the cost this closes, and it is quadratic in the wrong
 * dimension. The single-column index can only position at the START of the boundary subject's
 * token, so every page re-reads that subject's chain from the beginning and discards the prefix
 * it has already walked. It scales with how many rows one subject has accumulated, not with the
 * page size, so it grows exactly for the long-lived subjects whose proof matters most.
 *
 * WHY DROPPING IT IS SAFE. Every other read of `subject_token` in the package is leading-column
 * work the pair serves unchanged: equality (`DefaultConsentManager`), `whereIn` (the Art. 17
 * erasure), `whereNotNull`, and `GROUP BY subject_token`. Two of them get strictly better —
 * `PruneExpiredConsentRecordsCommand` reads a subject's rows as `where(subject_token)
 * ->orderBy(id)`, which IS this pair, and its `min(id) GROUP BY subject_token` aggregate becomes
 * an index-only candidate. `legal_consents_chain_link_unique` (subject_token, prev_record_hash)
 * also leads with the same column, so the single-column index had in fact been redundant for
 * lookups since 000012 added it.
 *
 * WHAT IT COSTS ON THE OTHER ENGINES: nothing, because there it was already this index under
 * another name. InnoDB appends the primary key to every secondary index, so MySQL's
 * `legal_consents_subject_token_index` is physically (subject_token, id) — measured on MySQL
 * 8.4.10, the seek already ran as one index range scan with `10101 < id <= 9999999` inside the
 * index condition, and all three states (before, both, after) produced an identical plan at an
 * identical cost of 4894. SQLite likewise stores the rowid as an index's trailing column and,
 * like PostgreSQL, ignored the pair until the single-column index was gone. So this migration is
 * a real change on PostgreSQL, the engine the package is primary on, and a rename everywhere else.
 *
 * No column is altered, so no table is rebuilt and the triggers installed by 000011 and 000012
 * survive — `ProofColumnGuard::whileDisarmed()` is for a migration that changes a COLUMN, and an
 * index is not one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_consents', function (Blueprint $table): void {
            $table->index(['subject_token', 'id'], 'legal_consents_token_id_idx');

            // The column list rather than the literal name, so the name is derived exactly the way
            // 000002 derived it when `$table->index('subject_token')` created it. Should the
            // framework's naming scheme ever move, both halves move together instead of this line
            // failing against an index that is demonstrably there.
            $table->dropIndex(['subject_token']);
        });
    }

    public function down(): void
    {
        Schema::table('legal_consents', function (Blueprint $table): void {
            $table->index('subject_token');
            $table->dropIndex('legal_consents_token_id_idx');
        });
    }
};
