<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Content;

use Carbon\CarbonImmutable;

/**
 * A fully resolved legal document: sanitized HTML, its content hash, the parsed
 * SemVer parts, and the delayed-notice timeline. This is the in-memory shape the
 * pipeline produces and the manager caches; persisting it freezes a LegalDocument row.
 *
 * The content hash is computed over the SANITIZED, canonicalized HTML — the "copy of
 * the information presented" a controller must be able to reconstruct (EDPB 05/2020
 * Rz. 108). A pure formatting change therefore never changes the hash.
 */
final readonly class Document
{
    public function __construct(
        public string $type,
        public string $locale,
        public string $title,
        public string $html,
        public string $contentHash,
        public string $version,
        public int $majorVersion,
        public int $minorVersion,
        public int $patchVersion,
        public bool $isMaterial,
        /**
         * The acceptance sentence, or NULL for a document that asks the reader for nothing —
         * an `informational` page (Impressum, cookie policy, accessibility statement). There
         * is no honest sentence for one, and this column is frozen proof, so the absence is
         * modeled rather than filled.
         */
        public ?string $uiWording,
        public ?CarbonImmutable $announceAt = null,
        public ?CarbonImmutable $enforceAt = null,
        public ?string $sourceRef = null,
    ) {}

    /**
     * Whether this document's enforcement window has opened at $now.
     */
    public function isEnforceable(CarbonImmutable $now): bool
    {
        return $this->enforceAt instanceof CarbonImmutable
            && $this->enforceAt->lessThanOrEqualTo($now);
    }
}
