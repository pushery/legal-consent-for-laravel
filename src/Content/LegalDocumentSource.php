<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Content;

use Pushery\LegalConsent\Exceptions\LegalDocumentNotFound;

/**
 * A content source: it resolves the raw text for a (type, locale) and exposes a
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
     * @throws LegalDocumentNotFound when no document exists for (type, locale)
     */
    public function resolve(string $type, string $locale): RawDocument;

    /**
     * A cheap, change-sensitive fingerprint of the current source state.
     */
    public function fingerprint(string $type, string $locale): string;
}
