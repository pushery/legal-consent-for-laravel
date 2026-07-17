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
 * is not yet published, and would let a refusal be relabelled as a render failure by callers that
 * catch broadly.
 *
 * Deliberately has NO fallback locale, unlike the recording path: the page must show the text of
 * the locale it claims to be showing, or nothing. Silently serving another language's contract
 * under a `de` URL is the kind of quiet substitution this package exists to prevent.
 */
final readonly class PublishedDocumentReader
{
    public function read(string $key, string $locale): ?PublishedDocument
    {
        $row = LegalDocument::query()
            // `content` is explicitly selected: $hidden only affects serialization, but the column
            // is excluded from the gate's own select for size, so name it here or it is absent.
            ->select([
                'id', 'key', 'locale', 'type', 'title', 'version', 'major_version',
                'content', 'content_hash', 'ui_wording', 'requires_reconsent', 'notice_mode',
                'published_at', 'enforce_from',
            ])
            ->where('key', $key)
            ->where('locale', $locale)
            ->where('is_active', true)
            ->first();

        return $row instanceof LegalDocument ? PublishedDocument::fromRow($row) : null;
    }
}
