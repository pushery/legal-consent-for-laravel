<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Facades;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Pushery\LegalConsent\Content\PublishedDocument;
use Pushery\LegalConsent\Contracts\ConsentManager;
use Pushery\LegalConsent\Enums\ConsentAction;
use Pushery\LegalConsent\Models\LegalConsent;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Support\ConsentContext;
use Pushery\LegalConsent\Support\RegistrationChecklistItem;

/**
 * @method static LegalConsent record(Model $subject, string $documentKey, ConsentAction $action, ConsentContext $context, ?string $locale = null)
 * @method static LegalConsent accept(Model $subject, string $documentKey, ConsentContext $context, ?string $locale = null, ?string $expectedContentHash = null)
 * @method static LegalConsent withdraw(Model $subject, string $documentKey, ConsentContext $context, ?string $locale = null)
 * @method static LegalConsent object(Model $subject, string $documentKey, ConsentContext $context, ?string $locale = null)
 * @method static LegalConsent terminate(Model $subject, string $documentKey, ConsentContext $context, ?string $locale = null)
 * @method static Collection<int, LegalDocument> outstanding(Model $subject, ?string $locale = null)
 * @method static bool hasCurrent(Model $subject, string $documentKey, ?string $locale = null)
 * @method static array<string, array<string, mixed>> statusFor(Model $subject, ?string $locale = null)
 * @method static list<array<string, mixed>> history(Model $subject)
 * @method static ?PublishedDocument published(string $documentKey, ?string $locale = null)
 * @method static list<RegistrationChecklistItem> registrationChecklist(?string $locale = null)
 *
 * @see ConsentManager
 */
final class Consent extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ConsentManager::class;
    }
}
