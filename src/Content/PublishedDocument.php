<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Content;

use Carbon\CarbonImmutable;
use Pushery\LegalConsent\Enums\DocumentType;
use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Models\LegalDocument;

/**
 * A PUBLISHED legal document, read verbatim from its frozen `legal_documents` row.
 *
 * This is the text a subject is shown and the text the ledger proves: `html` carries the exact
 * stored bytes and `contentHash` the exact stored hash, neither re-rendered nor re-sanitized on
 * the way out. That is the whole point — the page and the ledger are one text because they are
 * one column of one row (EDPB 05/2020 Rz. 108). Rendering the *source* instead is the silent
 * divergence this type exists to make impossible: a source can drift from the published row
 * between an author's edit and the next publish, and a subject would then accept text A while
 * the ledger snapshots hash(text B).
 *
 * Distinct from {@see Document}, which is the SOURCE rendered through the pipeline. Wiring the
 * wrong one into a public page is therefore a type mismatch, not a silent proof defect.
 */
final readonly class PublishedDocument
{
    public function __construct(
        public int $id,
        public string $key,
        public string $locale,
        public DocumentType $type,
        public string $title,
        public string $version,
        public int $majorVersion,
        public string $html,
        public string $contentHash,
        public string $uiWording,
        public NoticeMode $noticeMode,
        public ?CarbonImmutable $publishedAt = null,
        public ?CarbonImmutable $enforceFrom = null,
    ) {}

    public static function fromRow(LegalDocument $row): self
    {
        return new self(
            id: $row->id,
            key: $row->key,
            locale: $row->locale,
            type: $row->type,
            title: $row->title,
            version: $row->version,
            majorVersion: $row->major_version,
            html: $row->content,
            contentHash: $row->content_hash,
            uiWording: $row->ui_wording,
            noticeMode: $row->noticeMode(),
            publishedAt: $row->published_at,
            enforceFrom: $row->enforce_from,
        );
    }

    /**
     * Re-derive the hash from these exact bytes and compare it to the stored one — the row
     * proving itself. A mismatch means the stored content and its hash disagree, which the
     * immutability trigger is there to prevent; this is how an operator can confirm that from
     * the outside (see `legal-consent:verify-documents`).
     */
    public function verifyIntegrity(RenderPipeline $pipeline): bool
    {
        return $pipeline->hashOf($this->html) === $this->contentHash;
    }
}
