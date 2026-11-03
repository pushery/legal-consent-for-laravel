<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Pushery\LegalConsent\Content\AcceptanceWording;

/**
 * A document's heading from the vendor titles catalog, or its key when the catalog has none.
 *
 * Vendor lang, never draft content: the heading of a frozen version cannot be machine-translated
 * into the ledger by accident, and a status line names the document the way its reader knows it.
 */
final class DocumentTitle
{
    /**
     * The heading in `$locale`, the current locale when none is given.
     *
     * The language's own line comes first, so a regional locale such as `de_AT` is headed in German
     * rather than in the application's fallback language. A language the catalog has no line for
     * takes the fallback's, and a document no language names takes its key.
     */
    public static function for(string $key, ?string $locale = null): string
    {
        $declared = self::declared($key, $locale ?? app()->getLocale());

        if ($declared !== null) {
            return $declared;
        }

        $catalogKey = "legal-consent::titles.{$key}";
        $translated = trans($catalogKey, [], $locale);

        return is_string($translated) && $translated !== $catalogKey ? $translated : $key;
    }

    /**
     * The heading the titles catalog gives the document in this language, or null.
     *
     * Read without the application's `app.fallback_locale`, the way the acceptance sentence is read
     * ({@see AcceptanceWording}): a heading another language lends
     * is not one this language was given. A regional locale is answered from its language when it
     * has no line of its own, and an empty line counts as none.
     */
    public static function declared(string $key, string $locale): ?string
    {
        $translator = app('translator');
        $line = "legal-consent::titles.{$key}";

        foreach (self::languages($locale) as $candidate) {
            $translated = $translator->get($line, [], $candidate, false);

            if (is_string($translated) && $translated !== $line && trim($translated) !== '') {
                return $translated;
            }
        }

        return null;
    }

    /**
     * The locale, then its language when the locale names a region or a script as well.
     *
     * @return list<string>
     */
    private static function languages(string $locale): array
    {
        $parts = preg_split('/[-_]/', $locale);
        $language = is_array($parts) && $parts[0] !== '' ? $parts[0] : $locale;

        return array_values(array_unique([$locale, $language]));
    }
}
