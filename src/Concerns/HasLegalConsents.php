<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Concerns;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Pushery\LegalConsent\Contracts\ConsentManager;
use Pushery\LegalConsent\Enums\ConsentAction;
use Pushery\LegalConsent\Models\LegalConsent;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Support\ConsentContext;
use Pushery\LegalConsent\Support\ConsentGate;

/**
 * Give any Eloquent model a consent ledger. Because the subject is polymorphic, this
 * works on a User, an Organization, an ApiClient — anything with a key.
 *
 * @mixin Model
 */
trait HasLegalConsents
{
    /**
     * The subject's ledger, newest first.
     *
     * `id` is the tie-break, not decoration: `accepted_at` is second-granular on every engine the
     * package supports (Laravel pins `Builder::$defaultTimePrecision = 0`), so a grant and the
     * withdrawal that follows it share a timestamp whenever a subject clicks twice. Ordered by
     * `accepted_at` alone, `->first()` answers "does this subject still hold it" from an
     * unspecified row — and the answers differ per engine, because only SQLite happens to fall back
     * to insertion order. This is the same ordering
     * {@see ConsentGate::latestActionFor()} uses, so
     * the public relation and the package's own fold cannot disagree.
     *
     * @return MorphMany<LegalConsent, $this>
     */
    public function legalConsents(): MorphMany
    {
        return $this->morphMany(LegalConsent::class, 'subject')->latest('accepted_at')->latest('id');
    }

    public function recordConsent(string $documentKey, ConsentAction $action, ConsentContext $context, ?string $locale = null): LegalConsent
    {
        return app(ConsentManager::class)->record($this, $documentKey, $action, $context, $locale);
    }

    public function withdrawConsent(string $documentKey, ConsentContext $context, ?string $locale = null): LegalConsent
    {
        return app(ConsentManager::class)->withdraw($this, $documentKey, $context, $locale);
    }

    /**
     * @return Collection<int, LegalDocument>
     */
    public function outstandingLegalDocuments(?string $locale = null): Collection
    {
        return app(ConsentManager::class)->outstanding($this, $locale);
    }

    public function needsLegalConsent(?string $locale = null): bool
    {
        return app(ConsentManager::class)->outstanding($this, $locale)->isNotEmpty();
    }

    public function hasAcceptedCurrentLegal(string $documentKey, ?string $locale = null): bool
    {
        return app(ConsentManager::class)->hasCurrent($this, $documentKey, $locale);
    }

    /**
     * The same question as {@see hasAcceptedCurrentLegal()}, asked about several documents at once.
     *
     * The single-key method costs an active-document read plus a full fold of the subject's ledger,
     * and neither is memoized — so a template or a policy that checks three documents pays for the
     * same two reads three times, and the fold's cost grows with how long the subject has been a
     * customer. This resolves the whole set from the status map the manager already builds in one
     * fold and one document query, so the cost is flat in the number of keys.
     *
     * A key with no active document — a typo, a deactivated version, an informational page nobody
     * ever accepts — is `false`, the same answer the single-key method gives.
     *
     * @param  list<string>  $documentKeys
     * @return array<string, bool>
     */
    public function hasAcceptedCurrentLegalMany(array $documentKeys, ?string $locale = null): array
    {
        $status = app(ConsentManager::class)->statusFor($this, $locale);

        $held = [];

        foreach ($documentKeys as $documentKey) {
            $row = $status[$documentKey] ?? null;

            $held[$documentKey] = $row !== null && $row['accepted_major'] >= $row['current_major'];
        }

        return $held;
    }
}
