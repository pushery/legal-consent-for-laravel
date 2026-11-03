<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Content;

/**
 * The acceptance sentence the package translations hold for a document, in the document's own
 * language, or null when they hold none.
 *
 * This is the sentence a subject accepts, and it is frozen into the published version and copied
 * into every consent row that refers to it. So it is looked up without the application's
 * `app.fallback_locale`: a Polish document must not be published with the English sentence the
 * fallback would supply, recording that a Polish subject agreed to a sentence in English. A
 * regional locale such as `de_AT` or `pt-BR` is answered from its language, `de` or `pt`, when it
 * has no lines of its own, because that is still the subject's language. For every other locale
 * the answer is null, and the caller refuses to publish rather than borrow a sentence.
 *
 * The document's own line comes first, then the generic `default` one, each in the locale before
 * its language. An empty line counts as none: an empty sentence would be frozen as well, and it
 * renders as a required checkbox with no name.
 */
final class AcceptanceWording
{
    public static function for(string $documentKey, string $locale): ?string
    {
        return self::first(["legal-consent::wording.{$documentKey}", 'legal-consent::wording.default'], $locale);
    }

    /**
     * The document's own sentence, without the generic `default` one, or null when the translations
     * hold none for it in this language.
     */
    public static function own(string $documentKey, string $locale): ?string
    {
        return self::first(["legal-consent::wording.{$documentKey}"], $locale);
    }

    /**
     * Whether `$sentence` is the generic one, asked for because the document has no sentence of its
     * own in this language.
     *
     * A document the package does not ship starts out that way: it has no line until the application
     * writes one. The generic sentence names no document, and frozen into a version it is what every
     * consent under that version records, so the commands that show a release name it.
     */
    public static function isGeneric(string $documentKey, string $locale, ?string $sentence): bool
    {
        return $sentence !== null
            && self::own($documentKey, $locale) === null
            && $sentence === self::first(['legal-consent::wording.default'], $locale);
    }

    /**
     * The first of `$lines` the translations hold in the locale or its language, in that order.
     *
     * @param  list<string>  $lines
     */
    private static function first(array $lines, string $locale): ?string
    {
        $translator = app('translator');

        foreach (self::languages($locale) as $candidate) {
            foreach ($lines as $line) {
                $translated = $translator->get($line, [], $candidate, false);

                if (is_string($translated) && $translated !== $line && trim($translated) !== '') {
                    return $translated;
                }
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
