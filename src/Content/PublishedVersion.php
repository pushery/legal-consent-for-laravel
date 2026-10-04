<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Content;

use Carbon\CarbonImmutable;
use Pushery\LegalConsent\Enums\DocumentType;
use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Support\DefaultConsentManager;

/**
 * A published version without its text: what a form needs to show the acceptance sentence beside a
 * link to the document, and to record that a subject accepted it.
 *
 * It is read from the cached set of active documents the consent gate reads, so a form that renders
 * on every page asks for it without a query after the first. A page that shows the document itself
 * renders {@see PublishedDocument}, which carries the text.
 */
final readonly class PublishedVersion
{
    public function __construct(
        public int $id,
        public string $key,
        public string $locale,
        public DocumentType $type,
        public string $title,
        public string $version,
        public int $majorVersion,
        public string $contentHash,
        /**
         * The acceptance sentence, or NULL for an `informational` page, which asks the reader for
         * nothing and so has no sentence to carry.
         */
        public ?string $uiWording,
        public NoticeMode $noticeMode,
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
            contentHash: $row->content_hash,
            uiWording: $row->ui_wording,
            noticeMode: $row->noticeMode(),
            enforceFrom: $row->enforce_from,
        );
    }

    /** The same version, without the text a {@see PublishedDocument} carries. */
    public static function fromDocument(PublishedDocument $document): self
    {
        return new self(
            id: $document->id,
            key: $document->key,
            locale: $document->locale,
            type: $document->type,
            title: $document->title,
            version: $document->version,
            majorVersion: $document->majorVersion,
            contentHash: $document->contentHash,
            uiWording: $document->uiWording,
            noticeMode: $document->noticeMode,
            enforceFrom: $document->enforceFrom,
        );
    }

    /**
     * The value the accept-time guard compares against: the body hash folded with the acceptance
     * sentence. Equal to {@see PublishedDocument::acceptanceFingerprint()} for the same version.
     */
    public function acceptanceFingerprint(): string
    {
        return DefaultConsentManager::acceptanceFingerprint($this);
    }
}
