<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Facades;

use Illuminate\Support\Facades\Facade;
use Pushery\LegalConsent\Enums\BlockingReason;
use Pushery\LegalConsent\Models\LegalChangeSet;
use Pushery\LegalConsent\Support\ChangeItemsAuthor;
use Pushery\LegalConsent\Support\PendingChangeItems;

/**
 * @method static PendingChangeItems for(string $key, string $locale)
 * @method static ?LegalChangeSet draft(string $key, string $locale)
 * @method static ?LegalChangeSet published(int $documentId)
 * @method static bool discard(string $key, string $locale)
 * @method static array<string, BlockingReason> blockingLocales(string $key, list<string> $locales, ?string $currentFingerprint = null)
 *
 * @see ChangeItemsAuthor
 */
final class ChangeItems extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ChangeItemsAuthor::class;
    }
}
