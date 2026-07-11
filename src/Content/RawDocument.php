<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Content;

use Carbon\CarbonImmutable;

/**
 * What a content-source driver returns: the raw text plus whatever metadata the
 * source could supply. Deliberately "dumb" — no hashing, rendering, or sanitizing
 * happens here. The RenderPipeline turns this into a Document (the driver never does).
 *
 * `type` is the document key/type the caller asked for (e.g. 'terms'). Dates and
 * materiality are optional: a Markdown file can declare them in frontmatter, a
 * headless HTML source may leave them null and let the operator set them at publish.
 */
final readonly class RawDocument
{
    public function __construct(
        public string $type,
        public string $locale,
        public string $title,
        public string $body,
        public ContentFormat $format,
        public ?string $version = null,
        public ?CarbonImmutable $announceAt = null,
        public ?CarbonImmutable $enforceAt = null,
        public ?bool $isMaterial = null,
        public ?string $uiWording = null,
        public ?string $sourceRef = null,
    ) {}
}
