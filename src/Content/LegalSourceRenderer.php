<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Content;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Pushery\LegalConsent\Support\PublishedDocumentReader;
use Pushery\LegalConsent\Support\TenantContext;

/**
 * Renders the configured SOURCE — NOT the published row. This is a preview/drift tool.
 *
 * Warning: do not build a public legal page on this. It resolves the live source (a Markdown file, a
 * draft, a CMS row), renders and hashes it, and caches the result. That text can differ from the
 * published `legal_documents` row at any moment — between an author's edit and the next publish,
 * this returns the NEW text while the gate enforces, and the ledger snapshots, the OLD one. A page
 * built here would show a subject text A while proving they accepted hash(text B) — silently, with
 * nothing failing. That is the exact defect the ledger exists to prevent.
 *
 * For the text a subject must see and the ledger proves, use `Consent::published()`
 * ({@see PublishedDocumentReader}), which reads the frozen row's
 * verbatim bytes.
 *
 * The cached entry is keyed by a stable (type, locale) key but stores the source fingerprint
 * alongside the Document, so a content change auto-invalidates (fingerprint mismatch → re-render)
 * while an explicit flush stays trivial and portable (no cache-tag requirement).
 */
final readonly class LegalSourceRenderer
{
    public function __construct(
        private SourceFactory $sources,
        private RenderPipeline $pipeline,
        private CacheRepository $cache,
        private int $ttl = 86400,
        private string $prefix = 'legal:doc',
        private ?TenantContext $tenant = null,
    ) {}

    /**
     * The rendered SOURCE for (type, locale) — the pre-publish preview, never the proof.
     */
    public function renderSource(string $type, string $locale): Document
    {
        $source = $this->sources->for($type);
        $fingerprint = $source->fingerprint($type, $locale);
        $key = $this->cacheKey($type, $locale);

        $cached = $this->cache->get($key);

        if (is_array($cached)) {
            $document = $cached['document'] ?? null;

            if (($cached['fingerprint'] ?? null) === $fingerprint && $document instanceof Document) {
                return $document;
            }
        }

        $document = $this->pipeline->process($source->resolve($type, $locale));

        $this->cache->put($key, ['fingerprint' => $fingerprint, 'document' => $document], $this->ttl);

        return $document;
    }

    public function forget(string $type, string $locale): void
    {
        $this->cache->forget($this->cacheKey($type, $locale));
    }

    private function cacheKey(string $type, string $locale): string
    {
        // The tenant is part of the key: the fingerprint only auto-invalidates a stale entry when
        // it is itself tenant-unique, and a consumer CMS resolver's natural fingerprint (a content
        // hash, a version string) is not — so a tenant-blind key would serve one tenant another's
        // legal text. '' when tenancy is off, so the single-tenant key is unchanged in practice.
        $tenant = $this->tenant?->current() ?? '';

        return "{$this->prefix}:{$tenant}:{$type}:{$locale}";
    }
}
