<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Content;

use Carbon\CarbonImmutable;
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
    /**
     * The default cache-key namespace.
     *
     * ⚠️ IT CARRIES NO PAYLOAD VERSION, AND THAT IS A DECISION MADE AFTER MEASURING — a `:v2` was
     * written here and taken back out. Two reasons, either sufficient:
     *
     *  1. **It would not have taken effect.** The runtime value comes from
     *     `config('legal-consent.cache.prefix')`, which SHIPS as `legal:doc`; this constant is only
     *     the fallback. Every real installation — and every installation that published the config
     *     — would have kept the old namespace while the changelog announced a new one.
     *  2. **Nothing needs it.** The object→row change degrades to a cache MISS in both directions:
     *     old code meets a row and fails its `instanceof`, new code meets an object and fails
     *     {@see fromRow}. That is unlike the sibling cache's own versioning, which exists because
     *     its mismatch was an uncaught TypeError on a per-request path — a crash, not a miss.
     *
     * Public and named so a test can derive it. A test that hardcoded the literal key stopped
     * testing its own subject the moment this moved: the `:v2` experiment turned a
     * locale-resolution assertion red for a reason that had nothing to do with locales.
     */
    public const string PREFIX = 'legal:doc';

    public function __construct(
        private SourceFactory $sources,
        private RenderPipeline $pipeline,
        private CacheRepository $cache,
        private int $ttl = 86400,
        private string $prefix = self::PREFIX,
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

        if (is_array($cached) && ($cached['fingerprint'] ?? null) === $fingerprint) {
            $document = $this->fromRow($cached['document'] ?? null);

            if ($document instanceof Document) {
                return $document;
            }
        }

        $document = $this->pipeline->process($source->resolve($type, $locale));

        $this->cache->put($key, ['fingerprint' => $fingerprint, 'document' => $this->toRow($document)], $this->ttl);

        return $document;
    }

    /**
     * The cached shape: primitives only, never the {@see Document} itself.
     *
     * ⚠️ THIS USED TO CACHE THE OBJECT, and the sibling cache had already learned why that is wrong.
     * An application running a serializing store under `cache.serializable_classes` reads a cached
     * object back as `__PHP_Incomplete_Class`, so the `instanceof` guard on the read path failed on
     * every single hit — and the method then re-rendered and re-wrote, forever, with nothing going
     * red. A permanent silent cache miss is the worst shape a cache bug takes: it costs the render on
     * every call and reports success. `EnforceableDocumentCache` moved to primitive rows for exactly
     * this reason; this one was left behind.
     *
     * Both directions across the change degrade to a miss rather than a crash — old code reading a
     * row fails its `instanceof`, new code reading an object fails {@see fromRow} — which is why
     * this needs no key version, unlike the sibling cache whose mismatch was a TypeError. See
     * {@see PREFIX}.
     *
     * @return array<string, string|int|bool|null>
     */
    private function toRow(Document $document): array
    {
        return [
            'type' => $document->type,
            'locale' => $document->locale,
            'title' => $document->title,
            'html' => $document->html,
            'contentHash' => $document->contentHash,
            'version' => $document->version,
            'majorVersion' => $document->majorVersion,
            'minorVersion' => $document->minorVersion,
            'patchVersion' => $document->patchVersion,
            'isMaterial' => $document->isMaterial,
            'uiWording' => $document->uiWording,
            'announceAt' => $document->announceAt?->toIso8601String(),
            'enforceAt' => $document->enforceAt?->toIso8601String(),
            'sourceRef' => $document->sourceRef,
        ];
    }

    /**
     * Rebuild a {@see Document} from a cached row, or null for anything that is not one.
     *
     * Every field is type-checked rather than cast. A cache entry is untrusted input in the only
     * sense that matters here: it may have been written by another version of this package, and a
     * silent coercion would hand back a Document whose contentHash is the string "0" instead of
     * re-rendering the real one.
     */
    private function fromRow(mixed $row): ?Document
    {
        if (! is_array($row)) {
            return null;
        }

        foreach (['type', 'locale', 'title', 'html', 'contentHash', 'version'] as $string) {
            if (! isset($row[$string]) || ! is_string($row[$string])) {
                return null;
            }
        }

        foreach (['majorVersion', 'minorVersion', 'patchVersion'] as $int) {
            if (! isset($row[$int]) || ! is_int($row[$int])) {
                return null;
            }
        }

        if (! isset($row['isMaterial']) || ! is_bool($row['isMaterial'])) {
            return null;
        }

        foreach (['uiWording', 'announceAt', 'enforceAt', 'sourceRef'] as $nullable) {
            if (! array_key_exists($nullable, $row) || (! is_string($row[$nullable]) && $row[$nullable] !== null)) {
                return null;
            }
        }

        return new Document(
            type: $row['type'],
            locale: $row['locale'],
            title: $row['title'],
            html: $row['html'],
            contentHash: $row['contentHash'],
            version: $row['version'],
            majorVersion: $row['majorVersion'],
            minorVersion: $row['minorVersion'],
            patchVersion: $row['patchVersion'],
            isMaterial: $row['isMaterial'],
            uiWording: $row['uiWording'],
            announceAt: $row['announceAt'] === null ? null : CarbonImmutable::parse($row['announceAt']),
            enforceAt: $row['enforceAt'] === null ? null : CarbonImmutable::parse($row['enforceAt']),
            sourceRef: $row['sourceRef'],
        );
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
