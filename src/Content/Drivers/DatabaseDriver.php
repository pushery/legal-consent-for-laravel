<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Content\Drivers;

use Pushery\LegalConsent\Content\ContentFormat;
use Pushery\LegalConsent\Content\LegalDocumentSource;
use Pushery\LegalConsent\Content\RawDocument;
use Pushery\LegalConsent\Exceptions\LegalDocumentNotFound;
use Pushery\LegalConsent\Models\LegalDocument;

/**
 * Source mode where the legal text lives directly in the package's own
 * `legal_documents` table (edited via an admin UI, then published as a new active
 * version). Resolves the currently active version for a (key, locale).
 */
final readonly class DatabaseDriver implements LegalDocumentSource
{
    public function resolve(string $type, string $locale): RawDocument
    {
        $row = LegalDocument::query()
            ->where('key', $type)
            ->where('locale', $locale)
            ->where('is_active', true)
            ->first();

        if (! $row instanceof LegalDocument) {
            throw LegalDocumentNotFound::forSource($type, $locale, 'legal_documents');
        }

        return new RawDocument(
            type: $type,
            locale: $locale,
            title: $row->title,
            body: $row->content,
            format: ContentFormat::tryFrom($row->content_format) ?? ContentFormat::Html,
            version: $row->version,
            announceAt: $row->announce_from,
            enforceAt: $row->enforce_from,
            isMaterial: $row->requires_reconsent,
            uiWording: $row->ui_wording,
            sourceRef: 'legal_documents#'.$row->id,
        );
    }

    public function fingerprint(string $type, string $locale): string
    {
        $row = LegalDocument::query()
            ->select(['id', 'updated_at'])
            ->where('key', $type)
            ->where('locale', $locale)
            ->where('is_active', true)
            ->first();

        if (! $row instanceof LegalDocument) {
            return 'missing';
        }

        return $row->id.':'.($row->updated_at?->getTimestamp() ?? 0);
    }
}
