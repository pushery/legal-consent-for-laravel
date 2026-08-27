<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Content;

use Carbon\CarbonImmutable;
use Pushery\LegalConsent\Enums\DocumentType;
use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Support\DefaultConsentManager;
use Pushery\LegalConsent\Support\TenantContext;

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
        /**
         * The acceptance sentence, or NULL for an `informational` page — one that is published
         * and binds nobody (Impressum, cookie policy, accessibility statement). Such a page has
         * no sentence to carry, and this value is frozen proof, so the absence is modeled
         * rather than filled with a placeholder nobody said.
         */
        public ?string $uiWording,
        public NoticeMode $noticeMode,
        public ?CarbonImmutable $publishedAt = null,
        public ?CarbonImmutable $enforceFrom = null,
        /**
         * The tenant this row belongs to, or '' for the shared bucket (and for every
         * single-tenant app, where tenancy is off).
         *
         * Reads are already confined to the current tenant by the global scope on
         * LegalDocument, so this is not what makes the read safe — it is what lets a caller
         * CONFIRM what it received. Without it, a multi-tenant consumer inspecting a returned
         * document has no way to tell which tenant's text it is holding, and a returned
         * document looks equally valid either way.
         */
        public string $tenantId = '',
    ) {}

    public static function fromRow(LegalDocument $row): self
    {
        // Narrowed the same way TenantContext::current() narrows its resolver's return: the
        // column is `mixed` to the type system, and anything that is not a scalar id belongs
        // in the shared '' bucket rather than being coerced into a plausible-looking string.
        $tenant = $row->getAttribute(TenantContext::COLUMN);

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
            tenantId: is_string($tenant) || is_int($tenant) ? (string) $tenant : '',
        );
    }

    /**
     * What a subject reading THIS page was shown, as the one value the accept-time guard
     * compares against — the body hash folded with the acceptance sentence beside it.
     *
     * Delegates rather than recomputing. The rule it serves is that both sides of the guard run
     * the same code: a consumer rendering its own page and the bundled form must arrive at the
     * same value, and a second copy of `hash('sha256', …)` here would be exactly the drift the
     * fingerprint exists to prevent (change the separator once and the two quietly disagree).
     *
     * Recording `contentHash` instead is the mistake this method exists to make unnecessary:
     * that hash covers the sanitized BODY only, not the `uiWording` the subject actually read.
     */
    public function acceptanceFingerprint(): string
    {
        return DefaultConsentManager::acceptanceFingerprint($this);
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
