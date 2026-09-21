<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Pushery\LegalConsent\Content\PublishedDocument;
use Pushery\LegalConsent\Models\LegalDocument;

/**
 * Reads the frozen, published row — the ONE read path a public legal page should use.
 *
 * It resolves the active version with exactly the predicate the gate and the ledger use
 * (`key` + `locale` + `is_active`), so `hash(page bytes) === legal_consents.content_hash` is not
 * something a consumer maintains — it is a tautology.
 *
 * Deliberately NEVER throws: a missing publication returns null so a consumer renders an "in
 * preparation" shell. A throwing read path would 500 the public /terms page on the day a locale
 * is not yet published, and would let a refusal be relabeled as a render failure by callers that
 * catch broadly.
 *
 * NO fallback locale for a CONTRACT or a real CONSENT: the page must show the text of the locale it
 * claims to be showing, or nothing. Serving another language's contract under an `en` URL is the
 * kind of quiet substitution this package exists to prevent. An informational page and an
 * acknowledgment that asks for it are the exceptions, decided in ONE place so this path, the
 * release and the doctor cannot disagree: {@see SourceLanguageFallback}.
 */
final readonly class PublishedDocumentReader
{
    public function __construct(
        private string $defaultLocale = 'de',
    ) {}

    public function read(string $key, string $locale): ?PublishedDocument
    {
        $row = $this->activeRow($key, $locale) ?? $this->fallbackFor($key, $locale);

        return $row instanceof LegalDocument ? PublishedDocument::fromRow($row) : null;
    }

    /**
     * The cases where serving another locale is right rather than dangerous.
     *
     * An INFORMATIONAL page (Impressum, cookie policy, accessibility statement) is a legal duty to
     * PUBLISH, not something a subject accepts. Nobody is bound by it, and there is no acceptance
     * whose language could be misrepresented. What remains is the operator's duty to be reachable,
     * and a German Impressum shown to an English reader discharges that duty; an empty page does not
     * (§ 5 DDG). It comes from the default locale, as it always did.
     *
     * An ACKNOWLEDGMENT whose entry sets `locale_fallback` comes along the chain the gate walks to
     * decide what the same reader owes. The gate already holds a reader of an untranslated locale to
     * the version found there, so this is the read path showing them the text they are held to,
     * instead of an empty page next to a gate that points at it.
     *
     * The type is read from the ROW, not from the config registry: a contract published only in
     * `de` stays invisible under an `en` URL, exactly as before.
     */
    private function fallbackFor(string $key, string $locale): ?LegalDocument
    {
        foreach (RegistrationLocaleChain::resolve($locale, $this->defaultLocale) as $candidate) {
            if ($candidate === $locale) {
                continue;
            }

            $row = $this->activeRow($key, $candidate);

            if ($row instanceof LegalDocument && in_array($candidate, SourceLanguageFallback::standInLocales($key, $row->type, $locale, $this->defaultLocale), true)) {
                return $row;
            }
        }

        return null;
    }

    private function activeRow(string $key, string $locale): ?LegalDocument
    {
        return LegalDocument::query()
            // `content` is explicitly selected: $hidden only affects serialization, but the column
            // is excluded from the gate's own select for size, so name it here or it is absent.
            ->select([
                'id', 'key', 'locale', 'type', 'title', 'version', 'major_version',
                'content', 'content_hash', 'ui_wording', 'requires_reconsent', 'notice_mode',
                'published_at', 'enforce_from',
                // Selected so the returned DTO can carry it. Reads are already confined to the
                // current tenant by the global scope on LegalDocument — this is what lets a
                // multi-tenant caller CONFIRM which tenant's text it is holding.
                TenantContext::COLUMN,
            ])
            ->where('key', $key)
            ->where('locale', $locale)
            ->where('is_active', true)
            ->first();
    }
}
