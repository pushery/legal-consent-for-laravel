<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Pushery\LegalConsent\Enums\DocumentType;

/**
 * Whether a missing translation of a document may be answered with another language's version.
 *
 * Two questions this package used to answer with one field. `legal_basis` says whether a document
 * BINDS. Whether a reader of an untranslated locale may be shown another language's version was a
 * silent consequence of it: yes for an informational page, no for everything else. That made an
 * acknowledgment unusable the moment an application had more languages than translations. The
 * page it asks a reader to acknowledge was not reachable in the reader's language, and a release
 * had to cover every configured locale at once.
 *
 * The rule, in one place so the read path, the release and the doctor cannot disagree:
 *
 *  - An INFORMATIONAL page falls back, always, and only to the default locale, as it always did.
 *  - An ACKNOWLEDGMENT falls back when its registry entry says `'locale_fallback' => true`, along
 *    the same chain the gate walks to decide what a reader owes ({@see RegistrationLocaleChain}):
 *    `fallback_locale`, then `default_locale`. What is recorded for it is that somebody took notice,
 *    and the ledger row names the version AND the locale that was shown.
 *  - A CONTRACT and a real CONSENT never fall back, whatever the entry says. Both bind, and binding
 *    somebody to a text in a language they may not read is what the atomic release exists to
 *    prevent. An entry asking for it is refused here and reported by `legal-consent:doctor`.
 */
final class SourceLanguageFallback
{
    /** May a reader of an untranslated locale be shown this document in another language? */
    public static function allowedFor(string $key, DocumentType $type): bool
    {
        return match ($type) {
            DocumentType::Informational => true,
            DocumentType::PrivacyNotice => config("legal-consent.documents.{$key}.locale_fallback") === true,
            DocumentType::ContractTerms, DocumentType::ConsentOptin => false,
        };
    }

    /**
     * The locales another version may be taken from, closest first, for a reader of `$locale`.
     *
     * Empty when the document may not fall back, and empty for the default locale's own reader of an
     * informational page, which has nowhere closer to go.
     *
     * @return list<string>
     */
    public static function standInLocales(string $key, DocumentType $type, string $locale, string $defaultLocale): array
    {
        if (! self::allowedFor($key, $type)) {
            return [];
        }

        if ($type === DocumentType::Informational) {
            return $locale === $defaultLocale ? [] : [$defaultLocale];
        }

        return array_values(array_filter(
            RegistrationLocaleChain::resolve($locale, $defaultLocale),
            static fn (string $candidate): bool => $candidate !== $locale,
        ));
    }

    /**
     * Registry entries that ask for a fallback their legal basis cannot have.
     *
     * @return list<string> the document keys, in registry order
     */
    public static function refusedKeys(): array
    {
        $refused = [];

        foreach (DocumentMatrix::keys() as $key) {
            $basis = config("legal-consent.documents.{$key}.legal_basis");

            if (config("legal-consent.documents.{$key}.locale_fallback") === true && in_array($basis, ['contract', 'consent'], true)) {
                $refused[] = $key;
            }
        }

        return $refused;
    }
}
