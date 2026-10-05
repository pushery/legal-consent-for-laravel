<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as SupportCollection;
use Pushery\LegalConsent\Enums\ConsentAction;
use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Models\LegalDocument;

/**
 * Data for the non-blocking countdown banner shown DURING the grace period — after a
 * material change has been announced (announce_from) but before it is enforced
 * (enforce_from). This satisfies § 308 Nr. 5 BGB (reasonable period + notice of the
 * consequence) and lets the subject cancel/delete before the change takes effect.
 *
 * It returns only DATA — the optional Blade stub renders it (no framework UI here).
 */
final readonly class ConsentBanner
{
    public function __construct(
        private ConsentGate $gate,
        private string $defaultLocale = 'de',
    ) {}

    /**
     * Pending material changes the subject has not yet accepted, currently within their
     * grace window.
     *
     * @param  array<string, int>|null  $accepted  the subject's CURRENT holdings — a presence map, see
     *                                             ConsentGate::currentHoldings() — for at least the keys
     *                                             this banner asks about; read here, for those keys only,
     *                                             when null
     * @return list<array{key: string, version: string, title: string, announce_from: ?string, enforce_from: ?string, days_left: int}>
     */
    public function pendingFor(Model $subject, ?string $locale = null, ?CarbonImmutable $now = null, ?array $accepted = null): array
    {
        $now ??= CarbonImmutable::now();
        $upcoming = $this->upcomingReconsent($locale ?? $this->defaultLocale, $now);

        if ($upcoming->isEmpty()) {
            return [];
        }

        // Only the keys this banner asks about, as the gate reads them: the ledger grows for the life
        // of the account, and the rows of every other document would be folded only to be dropped.
        $accepted ??= $this->gate->currentHoldings($subject, $this->keysOf($upcoming));
        $pending = [];

        foreach ($upcoming as $document) {
            // A PRESENCE check — see ConsentGate::currentHoldings(). At major 0 the old
            // `($accepted[$key] ?? 0) >= $major` was true for everyone, so this banner stayed
            // silent for a document nobody had accepted.
            if (ConsentGate::holds($accepted, $document->key, $document->major_version)) {
                continue; // subject already accepted this version
            }

            $enforce = $document->enforce_from;

            // THE `?->` IN THIS PAYLOAD CANNOT FIRE, and the filter above is the reason: it
            // requires `announced()` (which demands a real `announce_from`) and
            // `enforce_from instanceof CarbonImmutable`. Both fields are therefore non-null by the
            // time they get here, so dropping the null-safe operator would change no outcome and
            // no test could tell. The same holds in informationalFor(), whose filter makes the
            // same two demands.
            //
            // deemedFor() is the exception and its `?->` IS load-bearing: that filter asks about
            // the OBJECTION DEADLINE -- the right question there, since silence binds at the
            // deadline rather than at the effective date -- and never looks at `enforce_from`, so
            // a row carrying a deadline and no effective date reaches its payload. That case has
            // a test; these two cannot have one.
            $pending[] = [
                'key' => $document->key,
                'version' => $document->version,
                'title' => $document->title,
                'announce_from' => $document->announce_from?->toIso8601String(),
                'enforce_from' => $enforce?->toIso8601String(),
                'days_left' => $enforce instanceof CarbonImmutable ? max(0, (int) $now->diffInDays($enforce)) : 0,
            ];
        }

        return $pending;
    }

    /**
     * Info-only changes (NoticeMode::InfoPush) currently in their notice window — a
     * dismissible "this was updated, no action required" banner, distinct from the
     * countdown grace banner. Info-only applies to everyone equally, so it is not
     * per-subject: it takes effect regardless of the subject's reaction.
     *
     * @return list<array{key: string, version: string, title: string, effective_from: ?string, offers_termination: bool}>
     */
    public function informationalFor(?string $locale = null, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $locale ??= $this->defaultLocale;

        $active = $this->resolvedFor($locale)
            // A silent correction published after an info-only change keeps that change's notice up
            // until its date: the banner reads the announced version the text belongs to.
            ->map(fn (LegalDocument $document): LegalDocument => $this->cache()->governingVersionOf($document))
            ->filter(
                // The stored column, not noticeMode(): a null notice_mode is NOT an info push (the
                // accessor would derive one from requires_reconsent), matching the previous SQL filter.
                //
                // The type is checked too, for the same reason the gate checks it: an informational
                // page can only be published silently, but this must not DEPEND on the publisher
                // having been the only way into the row. A hand-edited or restored row marked
                // info_push would otherwise put "please take notice" on the banner for an Impressum,
                // which asks the reader for nothing.
                fn (LegalDocument $document): bool => $document->type->isConsentBearing()
                    && $document->notice_mode === NoticeMode::InfoPush
                    && $this->announced($document, $now)
                    && $document->enforce_from instanceof CarbonImmutable
                    && $document->enforce_from->greaterThan($now)
            );

        $informational = [];

        foreach ($active as $document) {
            $informational[] = [
                'key' => $document->key,
                'version' => $document->version,
                'title' => $document->title,
                'effective_from' => $document->enforce_from?->toIso8601String(),
                'offers_termination' => (bool) $document->offers_termination,
            ];
        }

        return $informational;
    }

    /**
     * Deemed-consent (Zustimmungsfiktion) changes still within their objection window, for a
     * subject who is a party to the contract, holds an older version of it and has not yet
     * objected to or terminated — the banner that surfaces the objection / free-termination
     * options while the subject can still exercise them (§ 308 Nr. 5 lit. a BGB). The fiction
     * binds the parties and nobody else, so nobody else is offered an objection. `days_left`
     * counts down to the objection deadline, not the effective date.
     *
     * @param  array<string, int>|null  $accepted  the subject's CURRENT holdings — a presence map, see
     *                                             ConsentGate::currentHoldings() — for at least the keys
     *                                             this banner asks about; read here, for those keys only,
     *                                             when null. A key present in it is a contract the
     *                                             subject is a party to
     * @return list<array{key: string, version: string, title: string, objection_deadline: ?string, objection_date: ?string, enforce_from: ?string, days_left: int}>
     */
    public function deemedFor(Model $subject, ?string $locale = null, ?CarbonImmutable $now = null, ?array $accepted = null): array
    {
        $now ??= CarbonImmutable::now();
        $upcoming = $this->upcomingDeemed($locale ?? $this->defaultLocale, $now);

        if ($upcoming->isEmpty()) {
            return [];
        }

        $keys = $this->keysOf($upcoming);
        $accepted ??= $this->gate->currentHoldings($subject, $keys);
        $deemed = [];

        // Resolved for the WHOLE set at once, and only once the first document actually needs it.
        // This runs on every authenticated page render, and asking per document put one ledger
        // query inside the loop — 1 + N per render, where N is the number of objection windows open
        // at the same time. Those windows stay open for weeks and several contracts run in
        // parallel, so N is routinely more than one. Still lazy: a subject who is a party to none
        // of the changes pays nothing beyond the holdings.
        $standing = null;

        foreach ($upcoming as $document) {
            if (! isset($accepted[$document->key])) {
                continue; // not a party to this contract, so its silence binds nothing
            }

            // The version held and the latest action per key, from ONE read of the subject's
            // ledger for every key with an open window.
            $standing ??= $this->gate->standingFor($subject, $keys);
            $held = $standing['version'][$document->key] ?? null;

            // The VERSION, not the major. The publisher refuses a deemed change on a major bump of a
            // contract, so every party already holds the change's major, and comparing majors hid
            // the banner from exactly the subjects whose silence the window turns into agreement.
            // Compared by order rather than for equality: a later version accepted in another
            // locale is not an older one.
            if ($held === null || version_compare($held, $document->version, '>=')) {
                continue; // subject already holds this version
            }

            // Cross-locale: an objection/termination rebuts the change (key, major), whichever
            // locale's banner it was exercised through, so it suppresses the banner everywhere.
            $latest = $standing['latest'][$document->key]['action'] ?? null;

            if ($latest === ConsentAction::Objected->value || $latest === ConsentAction::Terminated->value) {
                continue; // the subject already objected or terminated
            }

            $deadline = $document->objection_deadline;

            $deemed[] = [
                'key' => $document->key,
                'version' => $document->version,
                'title' => $document->title,
                'objection_deadline' => $deadline?->toIso8601String(),
                // The calendar date the § 308 Nr. 5 lit. b warning names, in the format the durable
                // notice prints it in, so the banner repeats the notice's sentence rather than a second
                // rendering of the same instant.
                'objection_date' => $deadline?->toDateString(),
                'enforce_from' => $document->enforce_from?->toIso8601String(),
                'days_left' => $deadline instanceof CarbonImmutable ? max(0, (int) $now->diffInDays($deadline)) : 0,
            ];
        }

        return $deemed;
    }

    /**
     * All three banner datasets for a subject in one call — the re-consent countdown, the
     * info-only heads-up, and the deemed-consent objection window — so a single banner view
     * can render whichever apply.
     *
     * @return array{reconsent: list<array<string, mixed>>, informational: list<array<string, mixed>>, deemed: list<array<string, mixed>>}
     */
    public function forSubject(Model $subject, ?string $locale = null, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $locale ??= $this->defaultLocale;

        // The two per-subject banners share ONE read of the subject's holdings, for the keys either
        // of them asks about and no others: this runs on every authenticated render, for as long as
        // a window is open. Still lazy — with both windows closed nothing is read, so the common
        // "nothing pending" render stays query-free.
        $keys = $this->keysOf($this->upcomingReconsent($locale, $now)->concat($this->upcomingDeemed($locale, $now)));
        $accepted = $keys === [] ? null : $this->gate->currentHoldings($subject, $keys);

        return [
            'reconsent' => $this->pendingFor($subject, $locale, $now, $accepted),
            'informational' => $this->informationalFor($locale, $now),
            'deemed' => $this->deemedFor($subject, $locale, $now, $accepted),
        ];
    }

    /**
     * The documents whose re-consent window is open: announced, and not yet in force.
     *
     * @return SupportCollection<int, LegalDocument>
     */
    private function upcomingReconsent(string $locale, CarbonImmutable $now): SupportCollection
    {
        return $this->resolvedFor($locale)
            // The re-consent a document asks for is its major's: an editorial fix or an info-only
            // change published inside the major keeps the countdown of the version that opened it.
            // A document whose major asks for none stays itself, and the filter drops it.
            ->map(fn (LegalDocument $document): LegalDocument => $this->cache()->gatingVersionOf($document) ?? $document)
            ->filter(
                fn (LegalDocument $document): bool => ! $document->requires_explicit_optin
                    && $document->requires_reconsent
                    && $this->announced($document, $now)
                    && $document->enforce_from instanceof CarbonImmutable
                    && $document->enforce_from->greaterThan($now)
            );
    }

    /**
     * The deemed-consent changes whose objection window is open: announced, and the deadline not
     * passed.
     *
     * @return SupportCollection<int, LegalDocument>
     */
    private function upcomingDeemed(string $locale, CarbonImmutable $now): SupportCollection
    {
        return $this->resolvedFor($locale)
            // An objection window stays open across a silent correction of the text it concerns.
            ->map(fn (LegalDocument $document): LegalDocument => $this->cache()->governingVersionOf($document))
            ->filter(
                fn (LegalDocument $document): bool => $document->notice_mode === NoticeMode::DeemedConsent
                    && $this->announced($document, $now)
                    && $document->objection_deadline instanceof CarbonImmutable
                    && $document->objection_deadline->greaterThan($now)
            );
    }

    /**
     * The distinct keys of a set of documents, which is all a holdings read is filtered on.
     *
     * @param  SupportCollection<int, LegalDocument>  $documents
     * @return list<string>
     */
    private function keysOf(SupportCollection $documents): array
    {
        return array_values(array_unique($documents->map(fn (LegalDocument $document): string => $document->key)->all()));
    }

    /**
     * The document set a subject reading this locale is held to: the request locale, plus every
     * mandatory document resolved through the locale chain
     * ({@see EnforceableDocumentCache::resolvedFor()}), taken from the publish-invalidated cache
     * the package already maintains. On a warm cache in a store that is not the database (redis,
     * memcached, file, array), the banner's three global lookups cost no database query, instead of
     * three uncached `legal_documents` reads on every authenticated render. On the
     * framework-default `database` cache store the reads move to the cache table rather than
     * disappearing: the first read of each cached set in a request is a cache-table SELECT, and the
     * per-request memo answers every lookup after it. Point `LEGAL_CONSENT_CACHE_STORE` at a store
     * that is not the database for the full saving. The time filtering stays in memory: the
     * announce/enforce windows move on a clock, so caching an already-filtered set would need a TTL
     * short enough to be pointless.
     *
     * @return Collection<int, LegalDocument>
     */
    private function resolvedFor(string $locale): Collection
    {
        return $this->cache()->resolvedFor($locale);
    }

    private function cache(): EnforceableDocumentCache
    {
        return app(EnforceableDocumentCache::class);
    }

    /** Whether the document's announce window has opened. */
    private function announced(LegalDocument $document, CarbonImmutable $now): bool
    {
        return $document->announce_from instanceof CarbonImmutable
            && $document->announce_from->lessThanOrEqualTo($now);
    }
}
