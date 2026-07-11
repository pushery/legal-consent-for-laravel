<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

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
 */
final readonly class AffectedSubjectResolver
{
    public function __construct(private TenantContext $tenant) {}

    /**
     * @return LazyCollection<int, Model>
     */
    public function forVersion(LegalDocument $version): LazyCollection
    {
        $accepting = array_map(static fn (ConsentAction $action): string => $action->value, ConsentAction::accepting());

        return DB::table('legal_consents')
            ->select('subject_type', 'subject_id')
            ->where('document_key', $version->key)
            ->where('locale', $version->locale)
            // The notice sweep crosses tenants, so scope subjects to THIS version's tenant.
            ->when($this->tenant->enabled(), fn (QueryBuilder $query): QueryBuilder => $query->where('tenant_id', $version->tenant_id))
            ->whereNotNull('subject_type')
            ->whereNotNull('subject_id')
            ->whereIn('action', $accepting)
            ->groupBy('subject_type', 'subject_id')
            ->havingRaw('MAX(document_major_version) < ?', [$version->major_version])
            ->orderBy('subject_type')
            ->orderBy('subject_id')
            ->lazy()
            ->chunk(500)
            ->flatMap(fn (LazyCollection $chunk): Collection => $this->hydrate($chunk));
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
