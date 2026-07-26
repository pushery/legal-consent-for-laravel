<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Database\Eloquent\Builder;
use Pushery\LegalConsent\Content\PublishedDocument;
use Pushery\LegalConsent\Enums\DocumentType;
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
 * NO fallback locale for anything a subject agrees to, unlike the recording path: the page must
 * show the text of the locale it claims to be showing, or nothing. Silently serving another
 * language's contract under a `de` URL is the kind of quiet substitution this package exists to
 * prevent. An INFORMATIONAL page is the one exception, and only because the reasoning above does
 * not apply to it — see fallbackFor().
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
     * The one case where serving another locale is right rather than dangerous.
     *
     * An informational page (Impressum, cookie policy, accessibility statement) is a legal duty
     * to PUBLISH, not something a subject accepts. Nobody is bound by it, no hash of it is ever
     * frozen into a ledger row, and there is no acceptance whose language could be misrepresented
     * — the failure mode the no-fallback rule exists to prevent simply has no instance here. What
     * remains is the operator's duty to be reachable, and a German Impressum shown to an English
     * reader discharges that duty; an empty page does not (§ 5 DDG).
     *
     * The type is read from the ROW, not from the config registry, and the fallback query is
     * therefore constrained to informational rows: a contract published only in `de` stays
     * invisible under an `en` URL, exactly as before.
     */
    private function fallbackFor(string $key, string $locale): ?LegalDocument
    {
        if ($locale === $this->defaultLocale) {
            return null;
        }

        return $this->activeRow($key, $this->defaultLocale, informationalOnly: true);
    }

    private function activeRow(string $key, string $locale, bool $informationalOnly = false): ?LegalDocument
    {
        $row = LegalDocument::query()
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
            ->when($informationalOnly, static fn (Builder $query): Builder => $query->where('type', DocumentType::Informational->value))
            ->first();

        return $row instanceof LegalDocument ? $row : null;
    }
}
