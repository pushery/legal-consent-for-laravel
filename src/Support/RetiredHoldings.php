<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Pushery\LegalConsent\Models\LegalDocument;

/**
 * The documents a subject still holds according to the ledger, but which have no active version
 * left to show them under.
 *
 * Retiring a document is `is_active = false` — the supported route, the column being in
 * {@see LegalDocument::MUTABLE_AFTER_PUBLISH} — and it does not touch the ledger. The acceptance
 * stays on file, the gate's fold keeps reporting it as held, and the application keeps processing
 * on it. But every standing surface reads the ACTIVE set, so the document disappears from the one
 * screen the package ships for Art. 7(3) at the moment the subject most plausibly wants it: their
 * consent still binds and there is nothing left to press.
 *
 * The withdrawal itself already works — {@see DefaultConsentManager::documentForTransition()} falls
 * back to the version the subject accepted. This is the other half: making that route visible.
 *
 * ONE implementation, called by both surfaces (the presenter's settings screen and the manager's
 * status map), because two would be exactly the divergence between screens that this package
 * argues against everywhere else — and the two are already held to each other by a test.
 *
 * The version shown is the one the SUBJECT accepted, read back from their own ledger row, not the
 * last one the operator published. Their standing is a fact about the text they agreed to, and a
 * screen that names a different version is describing a document they never saw.
 */
final readonly class RetiredHoldings
{
    /**
     * @param  array<string, int>  $held  the subject's held major per document key, from the gate's fold
     * @param  list<string>  $activeKeys  the keys the caller is already showing from the active set
     * @return Collection<int, LegalDocument>
     */
    public function forSubject(Model $subject, array $held, array $activeKeys): Collection
    {
        $keys = array_values(array_diff(
            array_keys(array_filter($held, static fn (int $major): bool => $major > 0)),
            $activeKeys,
        ));

        // The common case by far, and it costs nothing: a subject whose documents are all still
        // published issues no query at all, so the standing screens keep the query budget they are
        // guarded at.
        if ($keys === []) {
            return new Collection;
        }

        $tenant = app(TenantContext::class);

        $versions = LegalDocument::query()
            // The same column set the presenter selects for an active row, `id` included: these
            // rows reach the host's `document_url` resolver on exactly the same footing.
            ->select(['id', 'key', 'title', 'version', 'major_version', 'type', 'locale', 'requires_explicit_optin'])
            ->whereIn('key', $keys)
            // RETIRED means no active version ANYWHERE, not merely none in the locale on screen.
            // A document published in `de` and not (yet) in `en` is not retired, and the rest of
            // the package already answers that way: the gate does not enforce it on the `en`
            // screen and `hasCurrent()` reports false for it. Without this arm a partially
            // published document would appear on the other locale's screen labeled as withdrawn
            // from service — a claim about the operator's registry that is simply untrue.
            ->whereNotExists(fn (QueryBuilder $live): QueryBuilder => $live->from('legal_documents as live')
                ->whereColumn('live.key', 'legal_documents.key')
                ->where('live.is_active', true)
                ->when($tenant->enabled(), fn (QueryBuilder $scoped): QueryBuilder => $scoped->where('live.tenant_id', $tenant->current())))
            // ONE query for the whole set rather than a ledger read per key: the subject's own rows
            // name the version they accepted by (key, locale, version), which is the triple that
            // identifies a version exactly — the reason migration 000008 could drop the foreign key.
            ->whereExists(fn (QueryBuilder $row): QueryBuilder => $row->from('legal_consents')
                ->whereColumn('legal_consents.document_key', 'legal_documents.key')
                ->whereColumn('legal_consents.locale', 'legal_documents.locale')
                ->whereColumn('legal_consents.document_version', 'legal_documents.version')
                ->where('legal_consents.subject_type', $subject->getMorphClass())
                ->where('legal_consents.subject_id', SubjectKey::for($subject))
                ->when($tenant->enabled(), fn (QueryBuilder $scoped): QueryBuilder => $scoped->where('legal_consents.tenant_id', $tenant->current())))
            // Deterministic, and `locale` is part of it: a retired row is shown in the language the
            // subject actually read it in. That is provenance rather than a display preference —
            // the document has no current version, so there is no "current" locale of it either.
            ->orderBy('key')
            ->orderByDesc('major_version')
            ->orderBy('locale')
            ->get();

        $chosen = [];

        foreach ($versions as $version) {
            $holding = $held[$version->key] ?? 0;
            $previous = $chosen[$version->key] ?? null;

            if (! $previous instanceof LegalDocument) {
                // Descending major, so the first row per key is the newest version the subject ever
                // acted on — the answer when their holding cannot be matched exactly, which happens
                // after a withdrawal followed by a fresh acceptance at a different major.
                $chosen[$version->key] = $version;

                continue;
            }

            if ((int) $previous->major_version !== $holding && (int) $version->major_version === $holding) {
                // An exact match for what the fold says they hold wins: that is the text binding
                // them right now.
                $chosen[$version->key] = $version;
            }
        }

        return new Collection(array_values($chosen));
    }
}
