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
 * Yields the subjects who must re-consent to a newly material version: those who once
 * accepted this document but only at an older major version. Streamed lazily in bounded
 * chunks and hydrated per subject_type in ONE query each (no N+1), so a large user base
 * never loads into memory at once (128 MB budget).
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
    public function forVersion(LegalDocument $version, ?int $maxConsentId = null): LazyCollection
    {
        $accepting = array_map(static fn (ConsentAction $action): string => $action->value, ConsentAction::accepting());

        $page = fn (): QueryBuilder => DB::table('legal_consents')
            ->select('subject_type', 'subject_id')
            ->where('document_key', $version->key)
            ->where('locale', $version->locale)
            // The notice sweep crosses tenants, so scope subjects to THIS version's tenant.
            ->when($this->tenant->enabled(), fn (QueryBuilder $query): QueryBuilder => $query->where('tenant_id', $version->tenant_id))
            ->when($maxConsentId !== null, fn (QueryBuilder $query): QueryBuilder => $query->where('id', '<=', $maxConsentId))
            ->whereNotNull('subject_type')
            ->whereNotNull('subject_id')
            ->whereIn('action', $accepting)
            ->groupBy('subject_type', 'subject_id')
            ->havingRaw('MAX(document_major_version) < ?', [$version->major_version])
            ->orderBy('subject_type')
            ->orderBy('subject_id')
            ->limit(self::CHUNK);

        // KEYSET paging on (subject_type, subject_id), NOT lazy()/chunk(): those page with
        // LIMIT/OFFSET, and OFFSET on a GROUP BY … HAVING query re-runs the whole aggregation every
        // page and discards the first OFFSET groups — quadratic. The group key is the sort key, so a
        // strict `> (lastType, lastId)` resumes where the last page ended — but HOW that seek is
        // written decides whether the whole sweep is linear (see the engine branch below). Both
        // forms lean on `legal_consents_affected_subject_idx` (document_key, locale, subject_type,
        // subject_id; migration 000013). Filtering those columns pre-aggregation is equivalent to
        // filtering groups — each group is one (subject_type, subject_id) pair.
        $driver = $this->keysetSeekDriver();

        return LazyCollection::make(function () use ($page, $driver): Generator {
            $lastType = null;
            $lastId = null;

            do {
                $query = $page();

                if ($lastType !== null) {
                    // PostgreSQL + SQLite range-scan the composite index for the sargable ROW-VALUE
                    // tuple form, so the whole sweep is LINEAR (measured ~20x faster than the OR form
                    // at 160k rows). MySQL's optimiser will NOT range-scan the index for a row-value
                    // comparison — it is slower that way — so MySQL keeps the OR form, where the
                    // index still cuts the constant enormously (seconds, not minutes) though that
                    // path stays super-linear. An engine-appropriate seek, not one shape for all.
                    if ($driver === 'mysql') {
                        $query->where(function (QueryBuilder $seek) use ($lastType, $lastId): void {
                            $seek->where('subject_type', '>', $lastType)
                                ->orWhere(fn (QueryBuilder $tie): QueryBuilder => $tie->where('subject_type', $lastType)->where('subject_id', '>', $lastId));
                        });
                    } else {
                        $query->whereRowValues(['subject_type', 'subject_id'], '>', [$lastType, $lastId]);
                    }
                }

                $rows = $query->get();

                foreach ($rows as $row) {
                    yield $row;
                }

                $last = $rows->last();
                $lastType = $last instanceof stdClass ? $last->subject_type : null;
                $lastId = $last instanceof stdClass ? $last->subject_id : null;
            } while ($rows->count() === self::CHUNK);
        })
            ->chunk(self::CHUNK)
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
     */
    public function countForVersion(LegalDocument $version, ?int $maxConsentId = null): int
    {
        $accepting = array_map(static fn (ConsentAction $action): string => $action->value, ConsentAction::accepting());

        $grouped = DB::table('legal_consents')
            ->select('subject_type', 'subject_id')
            ->where('document_key', $version->key)
            ->where('locale', $version->locale)
            ->when($this->tenant->enabled(), fn (QueryBuilder $query): QueryBuilder => $query->where('tenant_id', $version->tenant_id))
            ->when($maxConsentId !== null, fn (QueryBuilder $query): QueryBuilder => $query->where('id', '<=', $maxConsentId))
            ->whereNotNull('subject_type')
            ->whereNotNull('subject_id')
            ->whereIn('action', $accepting)
            ->groupBy('subject_type', 'subject_id')
            ->havingRaw('MAX(document_major_version) < ?', [$version->major_version]);

        return DB::query()->fromSub($grouped, 'affected')->count();
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
     * Reverse a stored subject_type back to a model class, honouring a configured morph
     * map (Relation::enforceMorphMap) — where subject_type is the ALIAS, not the FQCN.
     *
     * @return class-string<Model>|null
     */
    private function modelClass(int|string $type): ?string
    {
        if (! is_string($type)) {
            return null;
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        return is_a($class, Model::class, true) ? $class : null;
    }
}
