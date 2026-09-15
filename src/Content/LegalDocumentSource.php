<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Content;

use Pushery\LegalConsent\Exceptions\LegalDocumentNotFound;

/**
 * A content source: it resolves the raw text for a (document key, locale) and exposes a
 * CHEAP fingerprint of the current state. That is the whole contract — sources are
 * interchangeable, and the package "renders and proves, it does not own the content".
 *
 * The fingerprint is the cache-invalidation seam: it flows into the cache key, so a
 * content change auto-invalidates without observers. Keep it cheap (mtime+size,
 * updated_at, a row id) — never render or hash inside it.
 */
interface LegalDocumentSource
{
    /**
     * Resolve the raw text for a (document key, locale).
     *
     * `$type` carries the document key, the name the document is registered under in
     * `legal-consent.documents` ('terms', 'privacy', …), not a DocumentType value. The key
     * decides which text to return; the document's type comes from the registry.
     *
     * PIN the given `$locale` for anything you render or translate. A source that calls `__()` — or
     * otherwise reads the ambient app/session locale — hashes the same published text differently per
     * viewer, producing several conflicting hashes for one version and making `check-drift` flap
     * silently. Render in `$locale`, never the request locale. (This is the seam a consumer uses to
     * interpolate operator identity from a single source and still hash exactly what was shown.)
     *
     * @throws LegalDocumentNotFound when no document exists for (key, locale)
     */
    public function resolve(string $type, string $locale): RawDocument;

    /**
     * A cheap, change-sensitive fingerprint of the current source state for a (document key,
     * locale). `$type` is the same document key `resolve()` receives.
     */
    public function fingerprint(string $type, string $locale): string;
}
