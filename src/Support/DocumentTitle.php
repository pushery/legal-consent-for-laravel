<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

/**
 * A document's heading from the vendor titles catalog, or its key when the catalog has none.
 *
 * Vendor lang, never draft content: the heading of a frozen version cannot be machine-translated
 * into the ledger by accident, and a status line names the document the way its reader knows it.
 */
final class DocumentTitle
{
    public static function for(string $key, ?string $locale = null): string
    {
        $catalogKey = "legal-consent::titles.{$key}";
        $translated = trans($catalogKey, [], $locale);

        return is_string($translated) && $translated !== $catalogKey ? $translated : $key;
    }
}
