<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Content;

use Carbon\CarbonImmutable;

/**
 * What a content-source driver returns: the raw text plus whatever metadata the
 * source could supply. Deliberately "dumb" — no hashing, rendering, or sanitizing
 * happens here. The RenderPipeline turns this into a Document (the driver never does).
 *
 * `type` is the document key the caller asked for: the name the document is registered
 * under in `legal-consent.documents`, such as 'terms' or 'privacy'. It is not a
 * DocumentType value ('contract_terms', 'privacy_notice', …); the pipeline derives the
 * type from the registry entry for this key. Dates and materiality are optional: a
 * Markdown file can declare them in frontmatter, a headless HTML source may leave them
 * null and let the operator set them at publish.
 *
 * `sourceVersion` is the version as the source counts it, such as the whole number another
 * system gives each revision of a text. It is recorded beside the SemVer version, on the
 * published row and on every consent given to it, and it decides nothing: the re-consent gate
 * reads the SemVer version alone. A source that gives a source version and no SemVer version
 * publishes each new one as the next minor of the active version.
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
        public ?string $sourceVersion = null,
    ) {}
}
