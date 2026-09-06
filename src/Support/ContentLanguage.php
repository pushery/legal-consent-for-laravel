<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

/**
 * Which passages on a page are in a language the page does not claim.
 *
 * A mandatory document published only in the default locale still binds, so it appears in its own
 * language on a page rendered in another one — by design, not by accident: `RetiredHoldings` orders
 * by locale precisely so a retired row is shown in the language the subject actually read it in.
 *
 * Without `lang` on that passage a screen reader speaks it with the PAGE's phonetics, and the
 * sentence it mangles is the one the whole consent rests on. Art. 7(1) wants an informed consent;
 * one the subject cannot make out is not that. WCAG 3.1.2 (Language of Parts) is the same
 * requirement stated as a success criterion.
 *
 * ⚠️ `hreflang` is NOT this, and the shipped views used to carry only that one under a comment
 * citing 3.1.2. `hreflang` describes the language at the far end of a LINK; assistive technology
 * does not switch its voice on it. The two are set together and neither replaces the other.
 */
final class ContentLanguage
{
    /**
     * The language tag a passage has to declare, or null when the page already declares it.
     *
     * Null rather than the tag when they agree: `lang` on every element would be noise, and 3.1.2
     * asks about the parts that DIFFER. Null also for an empty or missing locale — `lang=""` is
     * itself a claim, and a wrong one.
     */
    public static function differingFrom(?string $documentLocale, ?string $pageLocale = null): ?string
    {
        $document = self::tag($documentLocale);

        if ($document === null) {
            return null;
        }

        $page = self::tag($pageLocale ?? app()->getLocale());

        // The PRIMARY subtag decides. A page on `en` and a document on `en-GB` are the same
        // language for a speech synthesizer, and declaring the difference buys nothing; `de`
        // against `en` is the case this exists for.
        return $page !== null && self::primary($document) === self::primary($page) ? null : $document;
    }

    /**
     * A Laravel locale as a BCP 47 language tag, or null when there is nothing to declare.
     *
     * Laravel writes a region with an underscore (`de_AT`); HTML wants a hyphen. Left as-is the
     * attribute is invalid and a user agent falls back to the page language — the exact failure
     * this class exists to prevent, arrived at by emitting an attribute rather than by omitting it.
     */
    private static function tag(?string $locale): ?string
    {
        $trimmed = trim((string) $locale);

        return $trimmed === '' ? null : str_replace('_', '-', $trimmed);
    }

    private static function primary(string $tag): string
    {
        return mb_strtolower(explode('-', $tag)[0]);
    }
}
