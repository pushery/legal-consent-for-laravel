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
        /**
         * The hash of the SOURCE text this HTML was rendered from, and a fingerprint of the
         * renderer that produced it. Both are frozen onto the published row, and together they are
         * what `legal-consent:check-drift` reads to tell a changed text from a changed rendering.
         *
         * NULL only where a Document is rebuilt from stored bytes instead of rendered — the cached
         * display document {@see LegalSourceRenderer} hydrates
         * needs neither, and inventing a value there would put a hash on a row nobody hashed.
         */
        public ?string $sourceHash = null,
        public ?string $renderFingerprint = null,
    ) {}

    /**
     * The same document under a different version number.
     *
     * The one case that needs it is the presentation re-render: the table an untouched text
     * suddenly renders as has to be frozen into a NEW row, because the published one is append-only
     * proof — and the version the source declares is the one already on file. So the re-render
     * publishes the next PATCH, carrying the identical text under a version the source never
     * mentions. Composed from the three parts rather than parsed from a string, so there is no
     * second place where a version format could be decided.
     */
    public function withVersion(int $major, int $minor, int $patch): self
    {
        return new self(
            type: $this->type,
            locale: $this->locale,
            title: $this->title,
            html: $this->html,
            contentHash: $this->contentHash,
            version: "{$major}.{$minor}.{$patch}",
            majorVersion: $major,
            minorVersion: $minor,
            patchVersion: $patch,
            isMaterial: $this->isMaterial,
            uiWording: $this->uiWording,
            announceAt: $this->announceAt,
            enforceAt: $this->enforceAt,
            sourceRef: $this->sourceRef,
            sourceHash: $this->sourceHash,
            renderFingerprint: $this->renderFingerprint,
        );
    }

    /**
     * Whether this document's enforcement window has opened at $now.
     */
    public function isEnforceable(CarbonImmutable $now): bool
    {
        return $this->enforceAt instanceof CarbonImmutable
            && $this->enforceAt->lessThanOrEqualTo($now);
    }
}
