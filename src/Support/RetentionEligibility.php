<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Pushery\LegalConsent\Models\LegalConsent;
use Pushery\LegalConsent\Models\LegalNotice;
use Pushery\LegalConsent\Models\Scopes\TenantScope;

/**
 * Which ledger rows the retention sweep may delete, and which chains it has to visit to delete them.
 *
 * Eligible = past the cutoff and (orphaned or superseded long enough ago). Orphaned = the subject was
 * deleted (no live relationship remains). Superseded = a newer row exists for the same tenant,
 * subject, document and locale, so this older one is no longer the current standing, and that newer
 * row is past the cutoff of the document too: it marks the moment this row stopped being relevant,
 * which is where its retention starts. The newest row per tenant has no such successor and is kept,
 * even past the cutoff, so an active subject's proof is never destroyed by age.
 *
 * The tenant belongs in the comparison because a standing is held per tenant: the same person in two
 * tenants has two relationships, and a newer row in one says nothing about the other. The sweep itself
 * runs across every tenant, which is why the comparison has to say so.
 *
 * A row that is eligible stays eligible while the sweep runs. The newest row of each (tenant, subject,
 * document, locale), which supersedes every other, is never removed, and a newer row past the cutoff
 * that the sweep does remove is removed only because a row newer still is past the cutoff as well,
 * which then supersedes this one in its place.
 */
final readonly class RetentionEligibility
{
    public function __construct(private RetentionCutoffs $cutoffs) {}

    /**
     * The eligible rows of a ledger.
     *
     * Every table name reaches the statement through the grammar, the correlated alias included, so
     * the connection's table prefix is applied on both sides of the comparison and no caller's value
     * is ever interpolated into it.
     *
     * @template TModel of LegalConsent|LegalNotice
     *
     * @param  Builder<TModel>  $query
     * @param  literal-string  $table  the ledger's table. It reaches the correlated subquery through
     *                                 the grammar rather than by interpolation, and a table name is not
     *                                 something a caller should be able to choose either way.
     * @return Builder<TModel>
     */
    public function rows(Builder $query, string $table, string $timestamp): Builder
    {
        // Retention runs across all tenants, and past the cutoff of each record's own document.
        return $this->cutoffs->expired($query->withoutGlobalScope(TenantScope::class), $timestamp)
            ->where(function (Builder $eligible) use ($table, $timestamp): void {
                $eligible
                    // Orphaned: the subject was deleted, so no live relationship remains.
                    ->whereNull('subject_id')
                    ->orWhereNull('subject_type')
                    // Superseded long enough ago: a newer row exists for the same (tenant, subject,
                    // document, locale), and it is itself past the retention of that document. The
                    // newer row's time is when this one stopped being the current standing, which is
                    // where its retention starts.
                    ->orWhereExists(function (QueryBuilder $newer) use ($table, $timestamp): void {
                        $newer
                            ->selectRaw('1')
                            ->from($table, 'newer')
                            ->whereColumn('newer.tenant_id', "{$table}.tenant_id")
                            ->whereColumn('newer.subject_type', "{$table}.subject_type")
                            ->whereColumn('newer.subject_id', "{$table}.subject_id")
                            ->whereColumn('newer.document_key', "{$table}.document_key")
                            ->whereColumn('newer.locale', "{$table}.locale")
                            ->whereColumn('newer.id', '>', "{$table}.id");

                        $this->cutoffs->expiredOn($newer, "newer.{$timestamp}", 'newer.document_key');
                    });
            });
    }

    /**
     * The tokens of the chains that hold an eligible row, in token order, after `$after`, asked row by
     * row: each row past the cutoff is checked for a newer one through an index probe.
     *
     * Cheap where eligible rows lie close together, because a page ends as soon as it is full. Where
     * they are few, a page reads every row past the cutoff before it can say so.
     *
     * @return list<string>
     */
    public function chainTokensRowByRow(?string $after, int $limit): array
    {
        return $this->tokens($this->rows(LegalConsent::model()::query(), 'legal_consents', 'accepted_at')
            ->whereNotNull('prev_record_hash')
            ->whereNotNull('subject_token')
            ->when($after !== null, fn (Builder $query): Builder => $query->where('subject_token', '>', $after))
            ->distinct()
            ->orderBy('subject_token')
            ->limit($limit)
            ->pluck('subject_token')
            ->all());
    }

    /**
     * The same tokens as {@see chainTokensRowByRow()}, the first `$limit` of them, from one pass over
     * the ledger instead of a probe per row.
     *
     * The newest row past the cutoff of each (tenant, subject, document, locale) is found once, by
     * grouping, and a row is superseded where that newest row is newer than it. Each row brings its own
     * token, so a subject whose rows were written under two tokens has the right chain visited for each
     * of them.
     *
     * One pass costs the same whether it finds nothing or thousands, so it is the cheaper search where
     * eligible rows are few, the ordinary state of a ledger swept every day. Asked again for the next
     * page it would read the whole ledger again, which is why a caller asks it once and pages on with
     * {@see chainTokensRowByRow()}.
     *
     * @return list<string>
     */
    public function chainTokensInOnePass(int $limit): array
    {
        $connection = LegalConsent::model()::query()->getConnection();

        $latest = $connection->table('legal_consents')
            ->select(['tenant_id', 'subject_type', 'subject_id', 'document_key', 'locale'])
            ->selectRaw('max(id) as newest_id')
            ->whereNotNull('subject_type')
            ->whereNotNull('subject_id');

        $this->cutoffs->expiredOn($latest, 'accepted_at', 'document_key')
            ->groupBy(['tenant_id', 'subject_type', 'subject_id', 'document_key', 'locale']);

        $candidates = $connection->table('legal_consents', 'candidate')
            ->leftJoinSub($latest, 'latest', static function (JoinClause $join): void {
                $join->on('latest.tenant_id', '=', 'candidate.tenant_id')
                    ->on('latest.subject_type', '=', 'candidate.subject_type')
                    ->on('latest.subject_id', '=', 'candidate.subject_id')
                    ->on('latest.document_key', '=', 'candidate.document_key')
                    ->on('latest.locale', '=', 'candidate.locale');
            })
            ->whereNotNull('candidate.prev_record_hash')
            ->whereNotNull('candidate.subject_token')
            ->where(static fn (QueryBuilder $eligible): QueryBuilder => $eligible
                ->whereNull('candidate.subject_id')
                ->orWhereNull('candidate.subject_type')
                ->orWhereColumn('latest.newest_id', '>', 'candidate.id'));

        return $this->tokens($this->cutoffs->expiredOn($candidates, 'candidate.accepted_at', 'candidate.document_key')
            ->distinct()
            ->orderBy('candidate.subject_token')
            ->limit($limit)
            ->pluck('candidate.subject_token')
            ->all());
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private function tokens(array $values): array
    {
        return array_values(array_filter($values, is_string(...)));
    }
}
