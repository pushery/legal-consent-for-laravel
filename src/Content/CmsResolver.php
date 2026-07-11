<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Content;

/**
 * The seam a consuming app implements to feed its OWN CMS into the package. The
 * package never learns what a "page" is — it only asks the resolver for a
 * RawDocument and a cheap fingerprint. (For example, an app whose legal texts live
 * in an `App\Models\Page` writes a small resolver that reads that model; the model
 * lives in the app, never here.)
 */
interface CmsResolver
{
    public function resolve(string $type, string $locale): RawDocument;

    public function fingerprint(string $type, string $locale): string;
}
