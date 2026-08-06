<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        // interleave with a release — this lock is the one that spans the outer transaction, while
        // activate()'s own is a savepoint inside it and would be released before the commit.
        $lock = Cache::lock(LegalDocument::activationLockName($key), 10);

        /** @var Collection<int, LegalDocument> $released */
        $released = $lock->block(5, fn (): Collection => $this->releaseNow($key, $mode, $locales, $options));

        return $released;
    }

    /**
     * The count of subjects a gating release actually reaches. Advisory ONLY — never a guard.
     *
     * A gating release whose major the whole population already holds re-gates nobody and still
     * reports success; that is worth SEEING. It is not worth throwing over: refusing a gating mode
     * on a non-major bump would force a typo fix on a live gating version down the editorial path,
     * which silently drops the gate for everyone still on the older major.
     */
    public function affects(LegalDocument $version): int
    {
        $maxConsentId = DB::table('legal_consents')->max('id');

        // Count the grouped set directly — never hydrate the whole affected population just to
        // size it (this runs in the release Livewire request).
        return $this->resolver->countForVersion($version, is_numeric($maxConsentId) ? (int) $maxConsentId : 0);
    }

    /**
     * @param  list<string>  $locales
     * @return Collection<int, LegalDocument>
     */
    private function releaseNow(string $key, NoticeMode $mode, array $locales, ReleaseOptions $options): Collection
    {
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
        if ($mode->requiresNotice() && (bool) config('legal-consent.change_items.required', false)) {
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
                ));
            }
        });

        return $published;
    }
}
