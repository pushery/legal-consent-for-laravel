<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Pushery\LegalConsent\Enums\ConsentAction;
use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Models\LegalConsent;
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
     *                                             ConsentGate::currentHoldings() — folded here when null and
     *                                             handed back so a sibling banner can reuse it
     * @return list<array{key: string, version: string, title: string, announce_from: ?string, enforce_from: ?string, days_left: int}>
     */
    public function pendingFor(Model $subject, ?string $locale = null, ?CarbonImmutable $now = null, ?array &$accepted = null): array
    {
        $now ??= CarbonImmutable::now();
        $locale ??= $this->defaultLocale;

        $upcoming = $this->activeFor($locale)->filter(
            fn (LegalDocument $document): bool => ! $document->requires_explicit_optin
                && $document->requires_reconsent
                && $this->announced($document, $now)
                && $document->enforce_from instanceof CarbonImmutable
                && $document->enforce_from->greaterThan($now)
        );

        if ($upcoming->isEmpty()) {
            return [];
        }

        $accepted ??= $this->gate->currentHoldings($subject);
        $pending = [];

        foreach ($upcoming as $document) {
            // A PRESENCE check — see ConsentGate::currentHoldings(). At major 0 the old
            // `($accepted[$key] ?? 0) >= $major` was true for everyone, so this banner stayed
            // silent for a document nobody had accepted.
            if (ConsentGate::holds($accepted, $document->key, $document->major_version)) {
                continue; // subject already accepted this version
            }

            $enforce = $document->enforce_from;

            // ⚠️ THE `?->` IN THIS PAYLOAD CANNOT FIRE, and the filter above is the reason: it
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

        $active = $this->activeFor($locale)->filter(
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
     * Deemed-consent (Zustimmungsfiktion) changes still within their objection window that
     * this subject holds an older major of and has not yet objected to or terminated — the
     * banner that surfaces the objection / free-termination options while the subject can
     * still exercise them (§ 308 Nr. 5 lit. a BGB). `days_left` counts down to the objection
     * deadline, not the effective date.
     *
     * @param  array<string, int>|null  $accepted  the subject's CURRENT holdings — a presence map, see
     *                                             ConsentGate::currentHoldings() — folded here when null and
     *                                             handed back so a sibling banner can reuse it
     * @return list<array{key: string, version: string, title: string, objection_deadline: ?string, enforce_from: ?string, days_left: int}>
     */
    public function deemedFor(Model $subject, ?string $locale = null, ?CarbonImmutable $now = null, ?array &$accepted = null): array
    {
        $now ??= CarbonImmutable::now();
        $locale ??= $this->defaultLocale;

        $upcoming = $this->activeFor($locale)->filter(
            fn (LegalDocument $document): bool => $document->notice_mode === NoticeMode::DeemedConsent
                && $this->announced($document, $now)
                && $document->objection_deadline instanceof CarbonImmutable
                && $document->objection_deadline->greaterThan($now)
        );

        if ($upcoming->isEmpty()) {
            return [];
        }

        $accepted ??= $this->gate->currentHoldings($subject);
        $deemed = [];

        // Resolved for the WHOLE set at once, and only once the first document actually needs it.
        // This runs on every authenticated page render, and asking per document put one ledger
        // query inside the loop — 1 + N per render, where N is the number of objection windows open
        // at the same time. Those windows stay open for weeks and several contracts run in
        // parallel, so N is routinely more than one. Still lazy: a subject who already holds every
        // announced version pays nothing, which is what the loop's first `continue` used to buy.
        $keys = array_values($upcoming->map(fn (LegalDocument $document): string => $document->key)->all());
        $latestActions = null;

        foreach ($upcoming as $document) {
            if (ConsentGate::holds($accepted, $document->key, $document->major_version)) {
                continue; // subject already holds this version
            }

            // Cross-locale: an objection/termination rebuts the change (key, major), whichever
            // locale's banner it was exercised through, so it suppresses the banner everywhere.
            $latestActions ??= $this->latestActionsFor($subject, $keys);
            $latest = $latestActions[$document->key] ?? null;

            if ($latest === ConsentAction::Objected || $latest === ConsentAction::Terminated) {
                continue; // the subject already objected or terminated
            }

            $deadline = $document->objection_deadline;

            $deemed[] = [
                'key' => $document->key,
                'version' => $document->version,
                'title' => $document->title,
                'objection_deadline' => $deadline?->toIso8601String(),
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
        // The two per-subject banners each fold the subject's held majors; share ONE fold so an open
        // reconsent AND deemed window no longer pays for it twice. Still lazy — neither folds at all
        // while its window is closed, so the common "nothing pending" render stays query-free.
        $accepted = null;

        return [
            'reconsent' => $this->pendingFor($subject, $locale, $now, $accepted),
            'informational' => $this->informationalFor($locale, $now),
            'deemed' => $this->deemedFor($subject, $locale, $now, $accepted),
        ];
    }

    /**
     * The active document set for a locale, taken from the publish-invalidated cache the package
     * already maintains — so the banner's three global lookups cost ZERO database queries on a warm
     * cache, on a NON-DB cache store (redis/memcached/file/array), instead of three uncached
     * legal_documents reads on every authenticated render. On the framework-default `database` cache
     * store each lookup is itself a cache-table SELECT, so the reads move to the cache table rather
     * than disappearing — point `LEGAL_CONSENT_CACHE_STORE` at a non-DB store for the full saving. The
     * time filtering stays in memory: the announce/enforce windows move on a clock, so caching an
     * already-filtered set would need a TTL short enough to be pointless.
     *
     * @return Collection<int, LegalDocument>
     */
    private function activeFor(string $locale): Collection
    {
        return app(EnforceableDocumentCache::class)->activeFor($locale);
    }

    /**
     * The subject's LAST ledger action per document key, for a whole set of keys in one read.
     *
     * Same answer as the gate's single-key lookup, arrived at from the same rows: that one orders
     * descending and takes the first, this one orders ascending and keeps overwriting, so the row
     * that wins is the same row. Cross-locale in both, because an objection rebuts the change
     * (key, major) whichever language it was exercised in.
     *
     * There is deliberately no early return for an empty key set. The only caller derives the keys
     * from the announced set it is already iterating and returns before the loop when that set is
     * empty, so it cannot ask this question about nothing: a guard here would be a branch no run
     * can enter, saving a query nobody requests. An empty set would answer correctly anyway — the
     * `whereIn` matches no row and the fold below yields nothing.
     *
     * @param  list<string>  $keys
     * @return array<string, ConsentAction>
     */
    private function latestActionsFor(Model $subject, array $keys): array
    {
        $latest = [];

        $rows = LegalConsent::query()
            ->select(['document_key', 'action'])
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', SubjectKey::for($subject))
            ->whereIn('document_key', $keys)
            ->orderBy('document_key')
            ->orderBy('accepted_at')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $latest[$row->document_key] = $row->action;
        }

        return $latest;
    }

    /** Whether the document's announce window has opened. */
    private function announced(LegalDocument $document, CarbonImmutable $now): bool
    {
        return $document->announce_from instanceof CarbonImmutable
            && $document->announce_from->lessThanOrEqualTo($now);
    }
}
