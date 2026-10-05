<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Pushery\LegalConsent\Content\PublishedVersion;
use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Models\LegalDocument;

/**
 * Caches WHICH versions are currently enforceable — a global, publish-driven fact — so the gate
 * stops paying a `legal_documents` query on every authenticated request.
 *
 * `outstandingFor()` ran its enforceable-set query unconditionally and only then early-returned on
 * an empty result, so a fresh install spent its entire pre-launch life paying one SELECT per
 * request to be told nothing is published.
 *
 * Only the SET of active versions is cached, never a subject's satisfaction and never the clock.
 * Whether a given human still owes acceptance is always computed live from the ledger, and the
 * callers compare `announce_from` and `enforce_from` with the time on every read, so a scheduled
 * boundary takes effect on time. The proof, what gets recorded when someone accepts, is never
 * cached either.
 *
 * Invalidated by publish (the listener on LegalDocumentPublished). The TTL backstops the one change
 * no event announces: an `is_active` write made outside a publish, such as a manual UPDATE or a
 * restored dump. Such a change stays unseen until the entry expires, or until
 * `legal-consent:cache-flush` drops it at once.
 */
final class EnforceableDocumentCache
{
    /**
     * THE `:v2` IS A PAYLOAD VERSION, NOT DECORATION — do not drop it, and bump it again the
     * next time the cached SHAPE changes.
     *
     * v0.5.0 changed what this key holds, from a serialized Eloquent collection to a list of
     * primitive attribute rows, and kept the key. On a shared cache store that is a live hazard in
     * one direction: code from ≤ v0.4.x reading a v2 payload hands the array straight through its
     * `: Collection` return type and throws an uncaught TypeError on the gate's per-request path.
     * Not a rolling deploy between two releases ≥ 0.5.0 — the shape has been stable since — but a
     * rollback or a mixed fleet crossing that boundary reproduces it every time.
     *
     * Versioning the key costs one string and makes the two payloads unable to meet. The orphaned
     * v1 entries expire on their own TTL; nothing has to clean them up.
     *
     * v3 adds `tenant_id` to each row, which {@see PublishedVersion} carries. A v2 row read under
     * the new code would hand every version the shared bucket's '' as its tenant.
     */
    public const string PREFIX = 'legal:enforceable:v3';

    /**
     * The suffix of the second entry per (tenant, locale): the announced versions an active one has
     * replaced inside its own major. A key of its own rather than a new payload shape, so code that
     * predates the set never reads it and the active set keeps the payload it has.
     */
    private const string BEHIND = 'behind';

    /**
     * The columns both cached sets carry: what the gate and the banner read.
     *
     * `is_active` is among them although every active row has it set: a consumer calling
     * isEnforceable() on a document handed out by the gate would otherwise read an unset is_active
     * as false, or, under Model::shouldBeStrict(), hit a MissingAttributeException.
     *
     * @var list<string>
     */
    private const array COLUMNS = [
        'id', 'key', 'locale', 'type', 'major_version', 'version', 'title', 'ui_wording',
        'content_hash', 'requires_explicit_optin', 'requires_reconsent', 'notice_mode',
        'announce_from', 'enforce_from', 'objection_deadline', 'offers_termination', 'is_active',
        'tenant_id',
    ];

    /**
     * The already-hydrated set per cache key, with the wall-clock second it stops being usable.
     *
     * The documented layout asks for this set FOUR times in one request — once from the gate's
     * middleware, three times from the banner — and a store read alone is not what that costs: the
     * rows are re-hydrated into models, casts and all, on every hit. On the framework-default
     * `database` store it is also four identical cache-table SELECTs. The set is one global fact
     * per (tenant, locale), so all four asks are the same answer.
     *
     * The lifetime is capped at the store TTL rather than left open, counted from when the memo was
     * built. A memo built from an entry read near its end can therefore outlive that entry by up to
     * the TTL, which takes a process that keeps one instance that long: the binding is scoped, so a
     * request, an Octane request and a queued job each start with a fresh instance, and a single
     * long-running command is the case that remains. A flush drops it immediately, so an
     * in-process publish is never invisible.
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
        private readonly string $defaultLocale = 'de',
    ) {}

    /**
     * Every active document a subject reading this locale is held to.
     *
     * Per document key, the version published in the requested locale. A MANDATORY document that is
     * not published in it resolves through the same chain registration walks, fallback_locale and then
     * default_locale ({@see RegistrationLocaleChain}), and the first version found stands in.
     *
     * {@see activeFor()} alone fails open for every untranslated locale. Read as the gate's set, the
     * strict per-locale answer leaves a subject browsing in a language the terms were never
     * translated into owing nothing: with terms in `de` and `en`, `outstanding()` would answer empty
     * for `fr`, `es`, `it`, `nl` and `pt`, and a subject in `it` who had accepted nothing would reach
     * the dashboard. Holdings are cross-locale, so the enforceable set is as well.
     *
     * Mandatory only, as registration does. A voluntary consent in another language is not one to ask
     * for, and an informational page binds nobody; both stay strictly per locale.
     *
     * The order is the per-locale order whenever nothing had to be resolved, so every existing list
     * renders as before. A resolved set is ordered by key, byte for byte.
     *
     * @return Collection<int, LegalDocument>
     */
    public function resolvedFor(string $locale): Collection
    {
        $documents = $this->activeFor($locale);
        $resolved = [];

        foreach ($documents as $document) {
            $resolved[$document->key] = $document;
        }

        $added = false;

        foreach (RegistrationLocaleChain::resolve($locale, $this->defaultLocale) as $candidate) {
            if ($candidate === $locale) {
                continue;
            }

            foreach ($this->activeFor($candidate) as $document) {
                if (! isset($resolved[$document->key]) && $document->type->isMandatory()) {
                    $resolved[$document->key] = $document;
                    $added = true;
                }
            }
        }

        if (! $added) {
            return $documents;
        }

        ksort($resolved, SORT_STRING);

        return new Collection(array_values($resolved));
    }

    /**
     * Every active document for a locale, cached. The caller filters by time and mode: the
     * enforce/announce windows move on a clock, so caching the ALREADY-filtered set would need a
     * TTL short enough to be pointless.
     *
     * The cached shape is a list of primitive attribute rows, never an `Eloquent\Collection` of
     * models. A serializing store (redis) run under `cache.serializable_classes => false` reads any
     * cached OBJECT back as `__PHP_Incomplete_Class`, which would then fail this method's
     * `: Collection` return on every cache HIT — an app-wide 500 on the gate's per-request path.
     *
     * Laravel 13's application skeleton ships `'serializable_classes' => false` in its
     * `config/cache.php`, so an application created from it runs under this setting unless someone
     * removed it. Only the framework's own fallback configuration leaves the key unset, which
     * `CacheManager::getSerializableClasses()` reads as null. An object in this cache is therefore
     * the common failure, not a rare one, and the shape here is primitive for that reason. Scalar
     * rows survive `unserialize(..., ['allowed_classes' => false])` untouched and are rehydrated to
     * models here. A cached value that is not a row list (a legacy object entry, a corrupt payload)
     * is treated as a miss and recomputed, so the gate degrades to a fresh read rather than
     * throwing.
     *
     * @return Collection<int, LegalDocument>
     */
    public function activeFor(string $locale): Collection
    {
        $key = $this->keyFor($locale);
        $now = CarbonImmutable::now()->getTimestamp();

        // The `0` is unobservable, and any other number would be too: this default is only read
        // when there is no memo entry, and then `$memoized` is null, so the check below fails on
        // its FIRST operand whatever the timestamp says. It is a shape, not a value.
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

        // A CACHE HIT STILL FIRES `retrieved`, ONCE PER DOCUMENT. `hydrate()` goes through
        // `newFromBuilder()`, which fires the model event whether the row came from the database
        // or from this cache. Before v0.5.0 a hit returned a stored collection and fired none, so
        // an application with a `LegalDocument::retrieved` listener sees accesses that never
        // touched the database. The per-request memo above means once per (tenant, locale) rather
        // than once per read — it does not mean zero, and a listener counting queries will
        // over-count.
        $documents = LegalDocument::model()::hydrate($rows);

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
        return LegalDocument::model()::query()
            ->select(self::COLUMNS)
            ->where('locale', $locale)
            ->where('is_active', true)
            // ORDERED, BECAUSE EVERYTHING DOWNSTREAM RENDERS THIS SET IN THIS ORDER. Without it
            // the engine decides: the banner listed pending changes in whatever sequence the
            // storage layer happened to return, so the same two documents came out in one order on
            // PostgreSQL and another on SQLite — and changed order again the moment an index was
            // added. Found exactly that way: a partial unique index on `legal_documents` flipped a
            // two-item list, and the arm that caught it was asserting a sequence nothing promised.
            //
            // `key` is the stable identity a reader recognizes; `id` breaks the tie for a document
            // published in several locales that reaches one list.
            ->orderBy('key')
            ->orderBy('id')
            ->get()
            ->map(static fn (LegalDocument $document): array => $document->getAttributes())
            ->all();
    }

    /**
     * The version that gates this document's major in its language: the document itself when it
     * was published as an active re-consent, otherwise the active re-consent that opened its major,
     * or null when the major never asked for one.
     *
     * A later version of a major replaces the active row, not what the major asked of its readers.
     * An active re-consent has to raise the major, so a major holds at most one, and an editorial
     * fix or an info-only change published inside it keeps that gate, from the effective date of
     * the version that opened it. Read from the active row alone, the gate ended with the first
     * such version for everyone who had not agreed yet.
     */
    public function gatingVersionOf(LegalDocument $document): ?LegalDocument
    {
        if ($document->noticeMode() === NoticeMode::ActiveReconsent) {
            return $document;
        }

        return $this->announcedVersionsOfTheMajor($document)
            ->first(static fn (LegalDocument $announced): bool => $announced->noticeMode() === NoticeMode::ActiveReconsent);
    }

    /**
     * The change this document's text belongs to: the document itself unless it was published
     * silently, otherwise the latest announced version of its major, or the document itself when
     * its major announced nothing.
     *
     * A silent version corrects the text of a change without being one, so what the change still
     * owes its readers, an info-only notice ahead of its date or an objection window still open,
     * is the announced version's.
     */
    public function governingVersionOf(LegalDocument $document): LegalDocument
    {
        if ($document->noticeMode() !== NoticeMode::SilentEditorial) {
            return $document;
        }

        return $this->announcedVersionsOfTheMajor($document)->last() ?? $document;
    }

    public function flush(string $locale): void
    {
        $key = $this->keyFor($locale);
        $behind = $key.':'.self::BEHIND;

        // The in-process copy goes with it. A publish that dropped only the shared entry would be
        // invisible to the very process that performed it for the rest of the request.
        unset($this->memo[$key], $this->memo[$behind]);

        $this->cache->forget($key);
        $this->cache->forget($behind);
    }

    /**
     * Forget the sets a write to this row can change, under the row's own tenant.
     *
     * The sets are keyed by the ambient tenant, and the ambient tenant is not always the row's: a
     * console command, a scheduled sweep and an operator writing across tenants run in the shared
     * bucket while the row belongs to its tenant. Flushed under the ambient tenant, that tenant's
     * gate kept the old set for its TTL.
     *
     * The row's own locale goes first, because a delete needs it: `flushAll()` finds locales in the
     * declared list and among the published rows, and a deleted row is in neither by then. A row can
     * carry a locale the list does not declare, one published before the list changed, and deleting
     * the last document of that locale would otherwise leave its set cached.
     */
    public function flushFor(LegalDocument $document): void
    {
        $tenant = $document->getAttribute(TenantContext::COLUMN);

        $this->tenant->forTenant(is_string($tenant) || is_int($tenant) ? (string) $tenant : '', function () use ($document): void {
            // `locale` is a NOT NULL column no write path leaves empty; the check keeps an empty
            // value from reading as a locale.
            if ($document->locale !== '') {
                $this->flush($document->locale);
            }

            // Every set is keyed per locale, and resolvedFor() walks a chain by reading each
            // locale's own set, so the flush above reaches every reader of this row. The full
            // flush is the wider net for a write that moved a version between locales.
            $this->flushAll();
        });
    }

    /** Forget every locale that could be cached — what a publish or an explicit flush needs. */
    public function flushAll(): void
    {
        // Cleared wholesale rather than per locale: a set memoized for a locale the app no longer
        // declares would otherwise outlive the flush that was meant to be total.
        $this->memo = [];

        foreach ($this->flushableLocales() as $locale) {
            $this->flush($locale);
        }
    }

    /**
     * Forget the declared locales only, for when the published ones cannot be read: a database
     * without this package's tables, before the first `migrate`, or none reachable at all, in a
     * build step.
     */
    public function flushDeclared(): void
    {
        $this->memo = [];

        foreach ($this->locales() as $locale) {
            $this->flush($locale);
        }
    }

    /**
     * Every locale a cached set could exist under: the declared ones, plus the ones actually
     * published.
     *
     * THE CONFIG ALONE IS NOT ENOUGH. A version stays published in a locale the list no longer
     * declares, because removing a locale from `legal-consent.locales` unpublishes nothing, and a
     * flush that read the list alone would leave that locale's set cached while the command
     * reports success.
     *
     * Reading the published locales back closes it at the only place that knows them. It is one
     * SELECT DISTINCT over a table with a row per document version, and it is paid for every row a
     * model write touches, after the write's transaction commits: a release pays it for each
     * version it inserts and activates, and once more per locale through the publish listener.
     * An explicit flush pays it once. Never on the request path.
     *
     * @return list<string>
     */
    private function flushableLocales(): array
    {
        $published = LegalDocument::model()::query()
            ->select('locale')
            ->distinct()
            ->pluck('locale')
            ->all();

        $locales = array_merge($this->locales(), array_filter($published, is_string(...)));

        return array_values(array_unique($locales));
    }

    /**
     * The announced versions of this document's major that an active version has replaced, oldest
     * first.
     *
     * @return Collection<int, LegalDocument>
     */
    private function announcedVersionsOfTheMajor(LegalDocument $document): Collection
    {
        return $this->behind($document->locale)
            ->filter(static fn (LegalDocument $announced): bool => $announced->key === $document->key
                && $announced->major_version === $document->major_version)
            ->values();
    }

    /**
     * The announced versions each active one has replaced inside its own major, for one locale:
     * the second half of what the gate reads, memoized and cached exactly like the active set and
     * flushed with it.
     *
     * @return Collection<int, LegalDocument>
     */
    private function behind(string $locale): Collection
    {
        $key = $this->keyFor($locale).':'.self::BEHIND;
        $now = CarbonImmutable::now()->getTimestamp();

        [$memoized, $expiresAt] = $this->memo[$key] ?? [null, 0];

        if ($memoized instanceof Collection && $expiresAt > $now) {
            return $memoized;
        }

        $cached = $this->cache->get($key);

        if (is_array($cached)) {
            $rows = $cached;
        } else {
            $rows = $this->freshRowsBehind($locale);
            $this->cache->put($key, $rows, $this->ttl);
        }

        $documents = LegalDocument::model()::hydrate($rows);

        $this->memo[$key] = [$documents, $now + $this->ttl];

        return $documents;
    }

    /**
     * Only the majors of active versions that are not an active re-consent themselves are read:
     * those are the ones whose obligations can sit on an earlier row. An installation whose active
     * versions all gate, or that has published nothing, pays no query here.
     *
     * @return array<int, array<string, mixed>>
     */
    private function freshRowsBehind(string $locale): array
    {
        $majors = [];

        foreach ($this->activeFor($locale) as $document) {
            if ($document->noticeMode() !== NoticeMode::ActiveReconsent) {
                $majors[$document->key] = $document->major_version;
            }
        }

        if ($majors === []) {
            return [];
        }

        return LegalDocument::model()::query()
            ->select(self::COLUMNS)
            ->where('locale', $locale)
            ->where('is_active', false)
            ->whereIn('key', array_keys($majors))
            ->where(static fn (Builder $announced): Builder => $announced
                ->where('notice_mode', '!=', NoticeMode::SilentEditorial->value)
                // A row from before the column states its mode through requires_reconsent alone,
                // which is how noticeMode() reads it.
                ->orWhere(static fn (Builder $legacy): Builder => $legacy->whereNull('notice_mode')->where('requires_reconsent', true)))
            ->orderBy('key')
            ->orderBy('id')
            ->get()
            ->filter(static fn (LegalDocument $document): bool => ($majors[$document->key] ?? null) === $document->major_version)
            ->map(static fn (LegalDocument $document): array => $document->getAttributes())
            ->values()
            ->all();
    }

    private function keyFor(string $locale): string
    {
        return self::PREFIX.':'.$this->tenant->current().':'.$locale;
    }

    /** @return list<string> */
    private function locales(): array
    {
        return DocumentMatrix::locales();
    }
}
