<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Carbon\CarbonImmutable;
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
final class EnforceableDocumentCache
{
    public const string PREFIX = 'legal:enforceable';

    /**
     * The already-hydrated set per cache key, with the wall-clock second it stops being usable.
     *
     * The documented layout asks for this set FOUR times in one request — once from the gate's
     * middleware, three times from the banner — and a store read alone is not what that costs: the
     * rows are re-hydrated into models, casts and all, on every hit. On the framework-default
     * `database` store it is also four identical cache-table SELECTs. The set is one global fact
     * per (tenant, locale), so all four asks are the same answer.
     *
     * The lifetime is capped at the store TTL rather than left open, which is what makes this safe
     * for an object that may live longer than a request (a queue worker, an Octane process): the
     * memo can never be staler than the cache entry it was built from, so the class's standing
     * promise — the worst a stale entry can do is delay the ONSET of a gate by the TTL — holds
     * either way. A flush drops it immediately, so an in-process publish is never invisible.
     *
     * It also means callers share one instance of each document rather than getting a private copy.
     * Every caller in the package reads; a caller that MUTATES a document handed out here would be
     * writing to a partially selected model, which the freeze rules refuse anyway.
     *
     * @var array<string, array{Collection<int, LegalDocument>, int}>
     */
    private array $memo = [];

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly TenantContext $tenant,
        private readonly int $ttl = 60,
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
        $now = CarbonImmutable::now()->getTimestamp();

        [$memoized, $expiresAt] = $this->memo[$key] ?? [null, 0];

        if ($memoized instanceof Collection && $expiresAt > $now) {
            return $memoized;
        }

        $cached = $this->cache->get($key);

        if (is_array($cached)) {
            $rows = $cached;
        } else {
            $rows = $this->freshRows($locale);
            $this->cache->put($key, $rows, $this->ttl);
        }

        $documents = LegalDocument::hydrate($rows);

        $this->memo[$key] = [$documents, $now + $this->ttl];

        return $documents;
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
        $key = $this->keyFor($locale);

        // The in-process copy goes with it. A publish that dropped only the shared entry would be
        // invisible to the very process that performed it for the rest of the request.
        unset($this->memo[$key]);

        $this->cache->forget($key);
    }

    /** Forget every locale the app declares — what a publish or an explicit flush needs. */
    public function flushAll(): void
    {
        // Cleared wholesale rather than per locale: a set memoized for a locale the app no longer
        // declares would otherwise outlive the flush that was meant to be total.
        $this->memo = [];

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
