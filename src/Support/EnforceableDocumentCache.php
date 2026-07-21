<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Collection;
use Pushery\LegalConsent\Models\LegalDocument;

/**
 * Caches WHICH versions are currently enforceable — a global, publish-driven fact — so the gate
 * stops paying a `legal_documents` query on every authenticated request.
 *
 * `outstandingFor()` ran its enforceable-set query unconditionally and only then early-returned on
 * an empty result, so a fresh install spent its entire pre-launch life paying one SELECT per
 * request to be told nothing is published.
 *
 * It cannot weaken the gate: only the enforceable SET is cached, never a subject's satisfaction.
 * Whether a given human still owes acceptance is always computed live from the ledger. The worst a
 * stale entry can do is delay the ONSET of a gate by the TTL — and the gate's own grace period is
 * measured in weeks, while the proof (what gets recorded when someone accepts) is never cached.
 *
 * Invalidated by publish (the listener on LegalDocumentPublished) plus a short TTL that backstops
 * the one change no event announces: a scheduled `enforce_from` boundary passing. An out-of-band
 * `is_active` write — a manual UPDATE, a restored dump — is not seen by either, so it needs
 * `legal-consent:cache-flush`.
 */
final readonly class EnforceableDocumentCache
{
    public const string PREFIX = 'legal:enforceable';

    public function __construct(
        private CacheRepository $cache,
        private TenantContext $tenant,
        private int $ttl = 60,
    ) {}

    /**
     * Every active document for a locale, cached. The caller filters by time and mode: the
     * enforce/announce windows move on a clock, so caching the ALREADY-filtered set would need a
     * TTL short enough to be pointless.
     *
     * The cached shape is a list of primitive attribute rows, never an `Eloquent\Collection` of
     * models. A serializing store (redis) run under Laravel's default `cache.serializable_classes
     * => false` reads any cached OBJECT back as `__PHP_Incomplete_Class`, which would then fail this
     * method's `: Collection` return on every cache HIT — an app-wide 500 on the gate's per-request
     * path. Scalar rows survive `unserialize(..., ['allowed_classes' => false])` untouched and are
     * rehydrated to models here. A cached value that is not a row list (a legacy object entry, a
     * corrupt payload) is treated as a miss and recomputed, so the gate degrades to a fresh read
     * rather than throwing.
     *
     * @return Collection<int, LegalDocument>
     */
    public function activeFor(string $locale): Collection
    {
        $key = $this->keyFor($locale);
        $cached = $this->cache->get($key);

        if (is_array($cached)) {
            $rows = $cached;
        } else {
            $rows = $this->freshRows($locale);
            $this->cache->put($key, $rows, $this->ttl);
        }

        return LegalDocument::hydrate($rows);
    }

    /**
     * The active-document rows as plain attribute arrays — the serialization-safe cache payload.
     * Only the columns the gate reads are selected, so a rehydrated model carries exactly what the
     * old cached model did.
     *
     * @return array<int, array<string, mixed>>
     */
    private function freshRows(string $locale): array
    {
        return LegalDocument::query()
            ->select([
                'id', 'key', 'locale', 'type', 'major_version', 'version', 'title', 'ui_wording',
                'content_hash', 'requires_explicit_optin', 'requires_reconsent', 'notice_mode',
                'announce_from', 'enforce_from', 'objection_deadline', 'offers_termination',
                // Every row here is active by definition, but the column must be SELECTED: a
                // consumer calling isEnforceable() on a document handed out by the gate would
                // otherwise read an unset is_active as false — or, under Model::shouldBeStrict(),
                // hit a MissingAttributeException.
                'is_active',
            ])
            ->where('locale', $locale)
            ->where('is_active', true)
            ->get()
            ->map(static fn (LegalDocument $document): array => $document->getAttributes())
            ->all();
    }

    public function flush(string $locale): void
    {
        $this->cache->forget($this->keyFor($locale));
    }

    /** Forget every locale the app declares — what a publish or an explicit flush needs. */
    public function flushAll(): void
    {
        foreach ($this->locales() as $locale) {
            $this->flush($locale);
        }
    }

    private function keyFor(string $locale): string
    {
        return self::PREFIX.':'.$this->tenant->current().':'.$locale;
    }

    /** @return list<string> */
    private function locales(): array
    {
        $locales = config('legal-consent.locales');

        return is_array($locales) ? array_values(array_filter($locales, is_string(...))) : [];
    }
}
