<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Generator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Pushery\LegalConsent\Enums\ConsentAction;
use Pushery\LegalConsent\Models\LegalDocument;
use stdClass;

/**
 * Yields the subjects a version is owed to — and WHICH subjects those are depends on the notice
 * mode, because "must re-consent" and "must be told" are two different populations.
 *
 * A GATING version (active re-consent) is owed to whoever the gate will actually stop: those who
 * accepted this document only at an older major. That is the same set the middleware enforces
 * against, so the notice and the block agree.
 *
 * A NON-GATING version (info-only, deemed consent) is owed to every current party, full stop.
 * § 327r Abs. 2, § 675g Abs. 1, P2B Art. 3(2) and DSA Art. 14(2) attach the duty to being a party,
 * not to holding an outdated version — and P2B Art. 3(3) makes a change implemented without it
 * void, so under-reaching here is the expensive direction.
 *
 * Restricting both to the major-version predicate made the non-gating modes unreachable: the
 * publisher refuses a non-gating mode on a major bump of a contract, so such a change is
 * necessarily a minor bump, and a minor bump never satisfies "MAX(major) < this major". The sweep
 * then reported success over an empty set and stamped its watermark, which is why nothing surfaced
 * it.
 *
 * Streamed lazily in bounded chunks and hydrated per subject_type in ONE query each (no N+1), so
 * peak memory is bounded by the chunk size rather than by the size of the population — a large
 * user base never loads at once.
 *
 * Not final: a test overrides {@see keysetSeekDriver} to cover the MySQL OR-form seek branch on the
 * SQLite suite (the protected-seam pattern DefaultConsentManager uses for its retry test).
 */
readonly class AffectedSubjectResolver
{
    private const int CHUNK = 500;

    public function __construct(private TenantContext $tenant) {}

    /**
     * The driver whose keyset-seek shape {@see forVersion} uses. A protected seam: a test forces the
     * `mysql` value to exercise (and prove correct) the OR-form branch on the SQLite suite; in
     * production this always resolves the live connection driver.
     */
    protected function keysetSeekDriver(): string
    {
        return DB::connection()->getDriverName();
    }

    /**
     * Whether {@see forVersion}'s keyset seek uses the sargable ROW-VALUE tuple. Only PostgreSQL and
     * SQLite range-scan the composite index for it. Every other engine keeps the portable OR/tie-break
     * form: MySQL will not range-scan the tuple, MariaDB reports its own driver name (`mariadb`, never
     * `mysql`, since Laravel 11), SQL Server does not compile the tuple to valid syntax, and an unknown
     * driver is unproven — so the row-value seek is opt-in per proven engine, not the default.
     */
    protected function usesRowValueSeek(string $driver): bool
    {
        return $driver === 'pgsql' || $driver === 'sqlite';
    }

    /**
     * How many subjects {@see forVersion} loads per page. A protected seam for the same reason
     * {@see keysetSeekDriver} is one: the paging is only exercised by CROSSING this boundary, and at
     * the production value that costs 501 rows per test. The three real-engine suites are exactly
     * where the seek shapes have to be proven — an engine decides whether the seek agrees with the
     * sort — and paying that fixture three times for a boundary a test can reach with six rows is
     * what kept them from being written at all.
     *
     * Read ONCE per call and reused for the limit, the loop condition and the hydration batch. A
     * value that changed between them would page past subjects it never yielded.
     */
    protected function chunkSize(): int
    {
        return self::CHUNK;
    }

    /**
     * @param  int|null  $maxConsentId  Only consider ledger rows up to this id — a snapshot of the
     *                                  ledger taken before the caller starts writing. The stream
     *                                  keyset-pages over a `HAVING MAX(major) < …` set, so a caller
     *                                  that APPENDS an accepting row per subject while iterating
     *                                  (the deemed-acceptance sweep) would shrink that set
     *                                  mid-stream and a later page could skip subjects it never
     *                                  returned. Pinning the ledger to a snapshot
     *                                  keeps the paged set immutable for the whole sweep; a re-run
     *                                  takes a fresh snapshot, which then correctly excludes the
     *                                  subjects already accepted.
     * @return LazyCollection<int, Model>
     */
    public function forVersion(LegalDocument $version, ?int $maxConsentId = null, bool $skipNotified = false): LazyCollection
    {
        $accepting = array_map(static fn (ConsentAction $action): string => $action->value, ConsentAction::accepting());
        $size = $this->chunkSize();

        $page = fn (): QueryBuilder => DB::table('legal_consents')
            ->select('subject_type', 'subject_id')
            ->where('document_key', $version->key)
            ->where('locale', $version->locale)
            // The notice sweep crosses tenants, so scope subjects to THIS version's tenant.
            //
            // ⚠️ `?? ''` IS THE FIX, AND THE `->when()` AROUND IT IS DELIBERATE — see below.
            // The column is NOT NULL DEFAULT '', but a model that was just CREATED has no value
            // loaded for it: measured, `$version->tenant_id` is null on a fresh insert and only
            // becomes '' after a refresh. `where('tenant_id', null)` compiles to IS NULL, which
            // matches no row of a NOT NULL column — the sweep resolves ZERO subjects and notifies
            // nobody, silently. With tenancy ON that was one un-refreshed model away.
            //
            // ⚠️ AND THE GUARD STAYS. Making this filter unconditional so `tenant_id` could LEAD
            // the affected-subject index looks correct — a NOT NULL DEFAULT '' column makes the
            // predicate hold in both modes — and it was built that way once. The suite refuted it:
            // with tenancy OFF, a version that belongs to a tenant must still
            // reach subjects whose consents sit in the shared bucket, because the stamping hook is
            // inert while tenancy is off and the proof row would otherwise be invisible to the very
            // tenant it belongs to. The dispatch suite defends exactly that. So the filter is
            // conditional by necessity, and 000013's reason for leaving tenant_id out of the index
            // stands — a leading column that is sometimes absent from the predicate cannot be one.
            ->when($this->tenant->enabled(), fn (QueryBuilder $query): QueryBuilder => $query->where('tenant_id', $version->tenant_id ?? ''))
            ->when($maxConsentId !== null, fn (QueryBuilder $query): QueryBuilder => $query->where('id', '<=', $maxConsentId))
            ->when($skipNotified, fn (QueryBuilder $query): QueryBuilder => $this->withoutAlreadyNotified($query, $version))
            ->whereNotNull('subject_type')
            ->whereNotNull('subject_id')
            ->whereIn('action', $accepting)
            ->groupBy('subject_type', 'subject_id')
            // The one mode-dependent condition. Everything else about this query — the keyset
            // paging, the tenant scoping, the resume predicate, the ledger snapshot — is identical
            // for both populations.
            ->when(
                $version->noticeMode()->gates(),
                fn (QueryBuilder $query): QueryBuilder => $query->havingRaw('MAX(document_major_version) < ?', [$version->major_version])
            )
            ->orderBy('subject_type')
            ->orderBy('subject_id')
            ->limit($size);

        // KEYSET paging on (subject_type, subject_id), NOT lazy()/chunk(): those page with
        // LIMIT/OFFSET, and OFFSET on a GROUP BY … HAVING query re-runs the whole aggregation every
        // page and discards the first OFFSET groups — quadratic. The group key is the sort key, so a
        // strict `> (lastType, lastId)` resumes where the last page ended — but HOW that seek is
        // written decides whether the whole sweep is linear (see the engine branch below). Both
        // forms lean on `legal_consents_affected_subject_idx` (document_key, locale, subject_type,
        // subject_id; migration 000013). Filtering those columns pre-aggregation is equivalent to
        // filtering groups — each group is one (subject_type, subject_id) pair.
        //
        // ⚠️ THE LINEARITY BELOW IS A SINGLE-TENANT CLAIM, and it is stated rather than left for a
        // reader to discover from a slow sweep. With tenancy ON, `tenant_id`
        // is a RESIDUAL filter — it is not in the index, so the seek still walks (document_key,
        // locale) in order but reads and discards the rows of every other tenant on the way. The
        // sweep stays linear in the row count for that (document_key, locale), not in the calling
        // tenant's own share of it, and a page can come back short after filtering.
        //
        // The obvious repair — filter unconditionally so `tenant_id` can LEAD the index — was built
        // and REFUTED by the dispatch suite, which is why the index is unchanged. See the note at
        // the filter itself: with tenancy off, a version that belongs to a tenant must still reach
        // subjects whose consents sit in the shared bucket, so the predicate cannot be
        // unconditional, and a leading column that is sometimes absent from the predicate cannot
        // lead an index.
        $driver = $this->keysetSeekDriver();

        return LazyCollection::make(function () use ($page, $driver, $size): Generator {
            $lastType = null;
            $lastId = null;

            do {
                $query = $page();

                if ($lastType !== null) {
                    // PostgreSQL + SQLite range-scan the composite index for the sargable ROW-VALUE
                    // tuple form, so the whole sweep is LINEAR (measured ~20x faster than the OR form
                    // at 160k rows). Every OTHER engine keeps the portable OR/tie-break form (see
                    // {@see usesRowValueSeek}): MySQL will not range-scan the index for a row-value
                    // comparison, MariaDB reports its own driver name, and an unknown driver may not
                    // compile whereRowValues at all — the index still cuts the constant enormously
                    // there, though that path stays super-linear. An engine-appropriate seek.
                    // ⚠️ INVERTING THIS BRANCH CHANGES NO RESULT, and the paragraph above says
                    // why: both seek forms resume at the same place and return the same rows.
                    // What the branch decides is whether the sweep stays LINEAR on this engine, and
                    // no assertion about the result can see that. The engine-shape arm covers which
                    // driver gets which form; the paging arms cover that both forms page correctly.
                    // Neither can see the difference, and a test that could would be timing the query.
                    if ($this->usesRowValueSeek($driver)) {
                        $query->whereRowValues(['subject_type', 'subject_id'], '>', [$lastType, $lastId]);
                    } else {
                        $query->where(function (QueryBuilder $seek) use ($lastType, $lastId): void {
                            $seek->where('subject_type', '>', $lastType)
                                ->orWhere(fn (QueryBuilder $tie): QueryBuilder => $tie->where('subject_type', $lastType)->where('subject_id', '>', $lastId));
                        });
                    }
                }

                $rows = $query->get();

                foreach ($rows as $row) {
                    yield $row;
                }

                $last = $rows->last();
                $lastType = $last instanceof stdClass ? $last->subject_type : null;
                $lastId = $last instanceof stdClass ? $last->subject_id : null;
            } while ($rows->count() === $size);
        })
            ->chunk($size)
            ->flatMap(fn (LazyCollection $chunk): Collection => $this->hydrate($chunk));
    }

    /**
     * How MANY subjects a version reaches, without hydrating any of them. `affects()` only needs the
     * number for an advisory line, so counting the grouped set with one aggregate query
     * (`COUNT(*) FROM (… GROUP BY subject HAVING MAX(major) < ?)`) is the right shape — the streaming
     * hydrate path of {@see forVersion} loads real subject models 500 at a time, which is wasteful
     * (and slow, and memory-heavy) purely to arrive at a count in a Livewire web request.
     *
     * This counts distinct (subject_type, subject_id) acceptance-groups in the ledger. It differs
     * from `forVersion(...)->count()` only for an ORPHANED group whose `subject_type` no longer maps
     * to a live model class — the hydrate path silently drops those (it cannot build the model),
     * this counts them. For any app whose subjects still exist the two are identical; and counting
     * every ledger population on the older major is the more faithful answer for an advisory number.
     *
     * `$skipNotified` applies {@see forVersion}'s resume predicate to the SAME grouped set, so a
     * caller that wants "how many does a resumed run still owe" no longer has to stream and hydrate
     * the remaining population to arrive at a number. It is the same predicate, from the same
     * method, so the two answers cannot drift.
     *
     * ⚠️ SWAPPING `forVersion(...)->count()` FOR THIS CHANGES THE REPORTED NUMBER for an orphaned
     * group, in the direction the paragraph above describes: such a group is counted here and
     * dropped there. That is the honest answer for an audience SIZE — the acceptance is on file and
     * the notice is owed to it — but it is not the number of notifications a run will manage to
     * send, so a line that promises deliveries has to say which of the two it is reporting.
     */
    public function countForVersion(LegalDocument $version, ?int $maxConsentId = null, bool $skipNotified = false): int
    {
        $accepting = array_map(static fn (ConsentAction $action): string => $action->value, ConsentAction::accepting());

        $grouped = DB::table('legal_consents')
            ->select('subject_type', 'subject_id')
            ->where('document_key', $version->key)
            ->where('locale', $version->locale)
            // Guarded and null-coerced for the same reasons as the sibling above.
            ->when($this->tenant->enabled(), fn (QueryBuilder $query): QueryBuilder => $query->where('tenant_id', $version->tenant_id ?? ''))
            ->when($maxConsentId !== null, fn (QueryBuilder $query): QueryBuilder => $query->where('id', '<=', $maxConsentId))
            ->when($skipNotified, fn (QueryBuilder $query): QueryBuilder => $this->withoutAlreadyNotified($query, $version))
            ->whereNotNull('subject_type')
            ->whereNotNull('subject_id')
            ->whereIn('action', $accepting)
            ->groupBy('subject_type', 'subject_id')
            ->when(
                $version->noticeMode()->gates(),
                fn (QueryBuilder $query): QueryBuilder => $query->havingRaw('MAX(document_major_version) < ?', [$version->major_version])
            );

        return DB::query()->fromSub($grouped, 'affected')->count();
    }

    /**
     * RESUMABILITY (notice sweep): skip subjects who already have a durable-medium proof row for
     * THIS version. A run killed or overtaken mid-sweep (the 120-min lock can expire on a large
     * population) resumes on the subjects still owed a notice instead of re-sending from the top —
     * and, crucially, it stops the concurrent/re-run case from writing a SECOND proof row for a
     * subject already notified. Delivery stays at-least-once (a proof written by a genuinely
     * simultaneous sweep in the same window is still tolerated — see the command docblock); this
     * removes the bulk of the duplication, not a unique constraint.
     *
     * ONE method, applied to both the streaming query and the counting one, because the whole point
     * of counting is to predict what the stream will serve. Two copies of this predicate would be
     * two answers to that question, and the one nobody runs is the one that rots.
     */
    private function withoutAlreadyNotified(QueryBuilder $query, LegalDocument $version): QueryBuilder
    {
        return $query->whereNotExists(
            fn (QueryBuilder $sub): QueryBuilder => $sub->from('legal_notices')
                ->whereColumn('legal_notices.subject_type', 'legal_consents.subject_type')
                ->whereColumn('legal_notices.subject_id', 'legal_consents.subject_id')
                ->where('legal_notices.document_id', $version->getKey())
        );
    }

    /**
     * @param  LazyCollection<int, stdClass>  $chunk
     * @return Collection<int, Model>
     */
    private function hydrate(LazyCollection $chunk): Collection
    {
        $models = new Collection;

        foreach ($chunk->groupBy('subject_type') as $type => $rows) {
            $class = $this->modelClass($type);

            if ($class === null) {
                continue; // unmapped / non-model subject_type — skip
            }

            $ids = $rows->pluck('subject_id')->all();

            foreach ($class::query()->whereKey($ids)->get() as $model) {
                $models->push($model);
            }
        }

        return $models;
    }

    /**
     * Reverse a stored subject_type back to a model class, honoring a configured morph
     * map (Relation::enforceMorphMap) — where subject_type is the ALIAS, not the FQCN.
     *
     * @return class-string<Model>|null
     */
    private function modelClass(int|string $type): ?string
    {
        // ⚠️ NO OUTCOME DEPENDS ON THIS, kept for readability rather than effect. `groupBy` coerces a
        // numeric subject_type to an INT key, which is the case this guard names -- but the check
        // below already answers null for it: measured, `getMorphedModel(123)` does not throw and
        // `is_a(123, Model::class, true)` is false. So RemoveEarlyReturn here changes nothing, and
        // the arm that covers the numeric type passes either way.
        //
        // It stays because "a non-string is not a morph type" is a statement about the input, and
        // reading that out of an is_a() two lines down is work the next person should not have to
        // do twice.
        if (! is_string($type)) {
            return null;
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        return is_a($class, Model::class, true) ? $class : null;
    }
}
