<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Pushery\LegalConsent\Enums\BlockingReason;
use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Exceptions\LegalReleaseNotReady;
use Pushery\LegalConsent\Models\LegalDocument;

/**
 * Publishes EVERY locale of a legal text as one change — all of them, or none.
 *
 * The per-locale publisher cannot express that, and the gap is not theoretical: publish `de` v2 as
 * an active re-consent while `en` lags, and German subjects are gated and notified while English
 * subjects are neither gated nor selected by the notice sweep — two populations living under two
 * different major versions of the SAME contract, with nothing in the package detecting it.
 * `check-drift` stays quiet too, because the English source still matches the English published
 * row. The cross-locale gate fix does not help here: the gate looks up enforceable documents in
 * the subject's own locale, so an English session simply never sees the German v2.
 *
 * So a release is atomic: one version, one major, one notice mode, every locale, one transaction.
 * The per-locale `legal-consent:publish` stays as the documented escape hatch for an urgent
 * single-locale correction — a package cannot force atomicity on an operator with a real emergency
 * — but it is the exception, and `assertModeConsistentAcrossLocales()` in the publisher keeps even
 * that path from splitting one change into two notice modes.
 */
final readonly class LegalDocumentReleaser
{
    public function __construct(
        private LegalDocumentPublisher $publisher,
        private AffectedSubjectResolver $resolver,
        private ChangeItemsAuthor $changeItems = new ChangeItemsAuthor(new TenantContext(false)),
    ) {}

    /**
     * @param  list<string>  $locales  every locale this text must exist in
     * @return Collection<int, LegalDocument>
     */
    public function release(string $key, NoticeMode $mode, array $locales, ReleaseOptions $options = new ReleaseOptions): Collection
    {
        // Serialize concurrent releases of the same text: two admins pressing "Release" at once
        // would otherwise race on the one-active-version guard and leave a half-applied set. The
        // SAME lock name LegalDocument::activate() uses, so a direct `legal-consent:publish` cannot
        // interleave with a release — this lock is the one that spans the outer transaction, and an
        // inner writer skips its own rather than re-taking this name.
        //
        // THIS PARAGRAPH USED TO CALL THE INNER LOCK "A SAVEPOINT INSIDE IT", AND THAT SENTENCE
        // IS HOW THE DEADLOCK GOT WRITTEN. A savepoint is a database construct that nests; this
        // lock lives in the cache store and knows nothing about the transaction it is taken in. It
        // does not nest, it CONTENDS — the inner instance is a different owner, so it waits out its
        // timeout and throws. Reported by a consumer who read this sentence, disbelieved it, and
        // was right. Whoever opens the transaction owns the lock, and nobody below re-takes it.
        //
        // The same name on a DIFFERENT store is no lock at all, and that is what this line used to
        // be: `Cache::lock(...)` resolves the app default, while the model resolves the package's
        // own `legal-consent.cache.store`. Both sides agree until an operator sets that option —
        // which the model's own docblock recommends — and then the two writers of a document's
        // active version queue on two separate locks and interleave freely. One resolver, called
        // from both sides, is the only shape that cannot drift again.

        // A release is the OUTERMOST writer of a document's active version, so it always takes the
        // lock — it never asks ActivationLock::ownedByCaller(), because there is nobody above it to
        // own it. The publisher it then calls once per locale asks exactly that question, and this
        // is the lock it finds already held.
        /** @var Collection<int, LegalDocument> $released */
        $released = ActivationLock::serialize(
            $key,
            'a release',
            fn (): Collection => $this->releaseNow($key, $mode, $locales, $options),
        );

        return $released;
    }

    /**
     * The count of subjects a gating release actually reaches. Advisory ONLY — never a guard.
     *
     * A gating release whose major the whole population already holds re-gates nobody and still
     * reports success; that is worth SEEING. It is not worth throwing over: refusing a gating mode
     * on a non-major bump would force a typo fix on a live gating version down the editorial path,
     * which silently drops the gate for everyone still on the older major.
     *
     * THE ANSWER IS PER (key, locale), because a consent row carries the locale it was given in.
     * A caller holding a whole release — one document across several locales — wants
     * {@see affectsRelease} instead: summing this over the released rows counts a subject who
     * accepted two languages twice, and reading one row answers about one language.
     */
    public function affects(LegalDocument $version): int
    {
        $maxConsentId = DB::table('legal_consents')->max('id');

        // Count the grouped set directly — never hydrate the whole affected population just to
        // size it (this runs in the release Livewire request).
        //
        // The 0 is EQUIVALENT under mutation: the watermark only bounds `id <=` in SQL, and an empty
        // ledger counts nobody below any number.
        return $this->resolver->countForVersion($version, is_numeric($maxConsentId) ? (int) $maxConsentId : 0);
    }

    /**
     * The version a change of this shape has to carry — the other half of the publisher's refusal.
     *
     * Since 0.32.0 the publisher refuses a gating mode on a version that keeps the active major,
     * because the gate asks whether a subject holds a document's major: an active re-consent
     * published as `1.1.0` reached nobody who had accepted `1.0.0`. That refusal names what is
     * wrong and, on its own, leaves the operator with the question it raises. The version still
     * comes from the draft row, which is typed by a person.
     *
     * So this answers it, and it ENFORCES NOTHING: a caller that has its own numbering keeps it.
     * What it removes is every consumer writing the same four lines — one of them did, measured
     * against 0.32.0, and its whole wrapper existed for little else.
     *
     *   gating      the next MAJOR. A text that has to be accepted again is a new major, and that
     *               is the only shape the gate can see.
     *   not gating  the next MINOR of the active version. An announced or editorial change keeps
     *               the major deliberately: raising it would gate nobody and re-ask everybody.
     *
     * A key with no active version at all answers `1.0.0` — a first publication gates its first
     * acceptance, and there is no earlier major to raise.
     *
     * The active row is read per key rather than per locale because a release writes one version
     * across every locale; where an escape-hatch publish has left them disagreeing, the HIGHEST is
     * the honest floor — the next version has to clear every language, not the one that lags.
     */
    public function nextVersionFor(string $key, NoticeMode $mode): string
    {
        $active = LegalDocument::query()
            ->where('key', $key)
            ->where('is_active', true)
            ->orderByDesc('major_version')
            ->orderByDesc('minor_version')
            ->orderByDesc('patch_version')
            ->first();

        if (! $active instanceof LegalDocument) {
            return '1.0.0';
        }

        return $mode->gates()
            ? ($active->major_version + 1).'.0.0'
            : $active->major_version.'.'.($active->minor_version + 1).'.0';
    }

    /**
     * The same advisory number for a whole release: every locale it covered, every person once.
     *
     * This is what the shipped manager reports after `release()`, and it is the only place the
     * number appears. It takes the rows the release returned rather than a key and a major, because
     * those rows ARE the release — the version that gates and the locales it reached both come from
     * the same object, so the count cannot describe a set the release did not write.
     *
     * An empty collection answers 0: nothing was released, so nobody was reached. That is not the
     * same as "no rows in the ledger", and no caller has to tell the two apart, because a release
     * that wrote nothing has already thrown.
     *
     * @param  Collection<int, LegalDocument>  $released
     */
    public function affectsRelease(Collection $released): int
    {
        $version = $released->first();

        if (! $version instanceof LegalDocument) {
            return 0;
        }

        $maxConsentId = DB::table('legal_consents')->max('id');

        return $this->resolver->countForRelease(
            $version,
            array_values($released->map(static fn (LegalDocument $row): string => $row->locale)->all()),
            is_numeric($maxConsentId) ? (int) $maxConsentId : 0,
        );
    }

    /**
     * @param  list<string>  $locales
     * @return Collection<int, LegalDocument>
     */
    private function releaseNow(string $key, NoticeMode $mode, array $locales, ReleaseOptions $options): Collection
    {
        // FIRST, whether this screen may release this document at all — before any question about
        // the drafts, and the order is the whole point.
        //
        // The two halves of a release read different texts. Readiness is judged over the DRAFTS,
        // three lines down; the publisher reads whatever source the document is configured for.
        // On `drafts` those are the same bytes and nothing is wrong. On any other source they are
        // not: a reviewed draft passes this pre-flight, and the publisher then freezes the file —
        // a text the reviewer never saw — as the evidence of what was in force. It reports success,
        // because from where it stands nothing failed.
        //
        // Asked before the draft check rather than after, because after it the answer would be
        // NoDraft for the ordinary case of a markdown document nobody wrote a draft for, and that
        // sentence is true about the wrong thing: it points at the draft store for a document that
        // does not use it. A reader following it writes a draft, reviews it, releases — and lands
        // exactly in the case this guard exists to stop.
        //
        // The console path is untouched: `legal-consent:publish` goes through the publisher
        // directly, which is the route a markdown document has always taken.
        //
        // THE PREDICATE MOVED to {@see DocumentSourceKind}, because the admin overview has to ask
        // the same thing to arm its button and used not to ask at all — so it armed one over a
        // release this line then refused. The paragraph above is the argument for saying the
        // source rather than the draft, and it was already written here; it simply never reached
        // the second reader. The reasoning about the third state — a source that does not resolve,
        // which is somebody else's error and stays theirs — lives with the predicate now.
        if (DocumentSourceKind::isOutsideTheDraftStore($key)) {
            throw LegalReleaseNotReady::for($key, array_fill_keys($locales, BlockingReason::NotDraftBacked));
        }

        // Pre-flight BEFORE the transaction: a release that cannot complete must not write a row
        // and roll it back, it must simply not start — and it must say which locales blocked it.
        $blocking = LegalDraftSet::for($key)->blockingLocales($locales);

        // The change DESCRIPTION is checked in the same pre-flight as the text, and for the same
        // reason: a release is one change across every locale, so a description present in German
        // and missing in Italian would send an Italian subject a notice that names no change. §
        // 327r Abs. 1 Nr. 3 BGB, Art. 12(1) GDPR and DSA Art. 14(1) all require the reader's own
        // language, and it is cheaper to refuse before the transaction than to freeze a half-set.
        //
        // Two conditions, and the second is the one that keeps this honest. The mode must owe a
        // notice at all — a silent editorial fix owes none. And the OPERATOR must have asked for
        // the requirement (`change_items.required`), because a package that started refusing every
        // existing release the day it shipped a new field would be forcing a feature, not offering
        // one. Off by default; the notice simply carries no delta until someone turns it on.
        if ($mode->requiresNotice() && config('legal-consent.change_items.required', false)) {
            // array_values() is EQUIVALENT under mutation, since blockingLocales() only iterates the locales.
            // Static analysis needs the list its signature declares (measured 2026-09-14).
            $blocking = [...$blocking, ...$this->changeItems->blockingLocales($key, array_values(array_diff($locales, array_keys($blocking))))];
        }

        if ($blocking !== []) {
            throw LegalReleaseNotReady::for($key, $blocking);
        }

        /** @var Collection<int, LegalDocument> $published */
        $published = new Collection;

        DB::transaction(function () use ($key, $mode, $locales, $options, $published): void {
            foreach ($locales as $locale) {
                $published->push($this->publisher->publishWithMode(
                    $key,
                    $locale,
                    $mode,
                    changeClass: $options->changeClass,
                    regime: $options->regime,
                    announceAt: $options->announceAt,
                    enforceAt: $options->enforceAt,
                    objectionDeadline: $options->objectionDeadline,
                    offersTermination: $options->offersTermination,
                    keepsUnmodified: $options->keepsUnmodified,
                    releasedTogether: $locales,
                ));
            }
        });

        return $published;
    }
}
