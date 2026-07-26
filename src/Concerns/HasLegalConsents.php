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

/**
 * Give any Eloquent model a consent ledger. Because the subject is polymorphic, this
 * works on a User, an Organization, an ApiClient — anything with a key.
 *
 * @mixin Model
 */
trait HasLegalConsents
{
    /**
     * @return MorphMany<LegalConsent, $this>
     */
    public function legalConsents(): MorphMany
    {
        return $this->morphMany(LegalConsent::class, 'subject')->latest('accepted_at');
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
}
