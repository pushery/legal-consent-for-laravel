<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Content;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * The read path for legal documents: resolve the configured source, render+hash once
 * through the pipeline, and cache the result. The cached entry is keyed by a stable
 * (type, locale) key but stores the source fingerprint alongside the Document, so a
 * content change auto-invalidates (fingerprint mismatch → re-render) while an explicit
 * flush stays trivial and portable (no cache-tag requirement).
 */
final readonly class LegalDocumentManager
{
    public function __construct(
        private SourceFactory $sources,
        private RenderPipeline $pipeline,
        private CacheRepository $cache,
        private int $ttl = 86400,
        private string $prefix = 'legal:doc',
    ) {}

    public function document(string $type, string $locale): Document
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
        return "{$this->prefix}:{$type}:{$locale}";
    }
}
