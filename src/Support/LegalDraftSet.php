<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Support\Collection;
use Pushery\LegalConsent\Enums\BlockingReason;
use Pushery\LegalConsent\Enums\DocumentType;
use Pushery\LegalConsent\Enums\ReviewState;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Models\LegalDraft;

/**
 * Every locale's draft of one legal text, loaded once — the level staleness actually lives at.
 *
 * Staleness is a RELATION between a translation and the source-locale text, not a property of a
 * row, so it is derived from two stored hashes rather than kept as a flag. A flag would need an
 * observer fanning out to every translation on each source edit: it can half-fail, and it fails
 * OPEN (reporting "fresh" for a text whose source moved) — the exact failure this exists to
 * prevent. A timestamp would be worse still: editing the source and reverting it leaves every
 * translation permanently "stale" with identical text.
 *
 * The three predicates are deliberately orthogonal — conflating them is how "stale" stops meaning
 * anything:
 *
 *   isStale()               the source text moved under this translation
 *   review_state            a human signed off on THESE EXACT bytes
 *   hasUnpublishedChanges() approved text is not live yet
 *
 * Precision comes free from the pipeline: `canonicalize()` runs inside the hash, so reformatting
 * the source stales nothing. Accepted caveat: that canonicalization's `\s` is not Unicode-aware,
 * so a non-breaking space (U+00A0) survives and DOES move the hash — pasting from a word processor
 * can stale every translation with no visible diff. Package semantics, documented, not papered
 * over with a second normalizer (that would be two canonical forms, and the ledger hashes one).
 */
final readonly class LegalDraftSet
{
    /**
     * @param  Collection<int, LegalDraft>  $drafts
     */
    private function __construct(
        public string $key,
        private Collection $drafts,
        private string $sourceLocale,
    ) {}

    /** All drafts for a key (tenant-scoped by the model), in ONE query. */
    public static function for(string $key, ?string $sourceLocale = null): self
    {
        $configured = config('legal-consent.default_locale');

        return new self(
            $key,
            LegalDraft::model()::query()->where('key', $key)->get(),
            $sourceLocale ?? (is_string($configured) ? $configured : 'de'),
        );
    }

    /** The source-locale draft — the text every translation is measured against. */
    public function source(): ?LegalDraft
    {
        return $this->draft($this->sourceLocale);
    }

    public function draft(string $locale): ?LegalDraft
    {
        return $this->drafts->firstWhere('locale', $locale);
    }

    /**
     * The version the next release carries, read from the SOURCE row for every locale — so the
     * locales of one release can never disagree about which version they are.
     */
    public function version(): ?string
    {
        return $this->source()?->version;
    }

    /**
     * The source text moved after this translation was made or last confirmed. The source locale
     * is never stale against itself.
     */
    public function isStale(LegalDraft $draft): bool
    {
        if ($draft->locale === $this->sourceLocale) {
            return false;
        }

        return $draft->source_hash !== $this->source()?->content_hash;
    }

    /**
     * Has this draft ever been confirmed against a source text AT ALL?
     *
     * The other half of {@see isStale()}, and it exists because that method answers two different
     * situations with one boolean. `save()` does not stamp `source_hash` — only `markReviewed()`
     * and `applyTranslation()` do — so a translation somebody typed by hand and never reviewed has
     * no stamp and is stale, correctly: nobody has confirmed it against today's source.
     *
     * What is NOT correct is telling that person the source changed *after this translation was
     * reviewed*. It never was. A screen that narrates a sequence of events which did not happen is
     * worse than one that says less, and this one is read by whoever decides to publish.
     */
    public function wasConfirmedAgainstASource(LegalDraft $draft): bool
    {
        return $draft->source_hash !== null;
    }

    /** The gate's predicate: a human signed off on these exact bytes, and the source has not moved. */
    public function isPublishable(LegalDraft $draft): bool
    {
        return $draft->review_state === ReviewState::Reviewed && ! $this->isStale($draft);
    }

    /**
     * The locales a release of THIS document covers, out of the ones an application configured.
     *
     * A release is atomic across locales because a subject must never be bound in a language they
     * did not read: German gated while Italian lags would leave two populations under two majors of
     * the same contract. That reasoning covers a contract and a real opt-in, and it covers nothing
     * at all for an INFORMATIONAL page — an imprint, a cookie notice, an accessibility statement.
     * Those bind nobody and gate nobody, so there is no half-released state for the atomicity to
     * prevent, and the read path serves another locale's version for exactly these rows.
     *
     * Nor for an ACKNOWLEDGMENT whose entry sets `locale_fallback`. The gate holds a reader of an
     * untranslated locale to the version the chain finds, so there is one population under one
     * major, and the read path shows that reader the same version. The rule for which document may
     * fall back lives in {@see SourceLanguageFallback}, and this reads it rather than restating it.
     *
     * Without this, one missing translation kept such a page off the site entirely: the capability
     * was there and the route to it was closed. Measured in a consumer with three informational
     * documents out of six, which narrowed the list itself rather than go without an imprint.
     *
     * A LANGUAGE COMES ALONG ONLY WHEN ITS DRAFT COULD GO OUT, and the source always comes along.
     * This used to drop only a language with no draft at all and keep one whose draft was written
     * but not reviewed, on the argument that an unreviewed translation is work an operator can act
     * on. It is, and it was also the only way forward: an unreviewed English draft held back the
     * corrected German imprint, and nothing released it except reviewing a translation nobody was
     * waiting for. The same state was reported from the screen and measured in a consumer, which
     * narrowed the list itself. A language whose draft is not ready keeps the version it already
     * has live, or serves the default locale where it has none — what a language without any draft
     * has always done.
     *
     * The source is the exception in the other direction. Every other language falls back to it,
     * so a release without it has nothing to fall back to. When the source is not ready the release
     * is the source alone, and the refusal names the source and its reason rather than publishing
     * the translations around it. The same holds when nothing is written at all: the page blocks on
     * its source, the one language that can hold it back, rather than on every configured one.
     *
     * An application whose default locale is not among its configured ones has no source in this
     * list to anchor on, and keeps the narrowing without it: whatever is ready, or every configured
     * language when nothing is, so the refusal still names them.
     *
     * IT LIVES HERE BECAUSE THREE PLACES ASK IT AND THEY DISAGREED. Until it moved here, the answer
     * sat in a PRIVATE method of the admin grid's component, so it reached the release button and
     * nothing else: the grid computed its blocking flags over every configured locale, and the
     * editor's own release narrowed nothing at all. An informational page could therefore read as
     * blocked on a screen whose button would have released it. The document's type is resolved from
     * this set's own key rather than passed in, so a caller cannot answer the question differently
     * by handing over a different type.
     *
     * @param  list<string>  $configured
     * @return list<string>
     */
    public function releaseLocales(array $configured): array
    {
        if (! SourceLanguageFallback::allowedFor($this->key, $this->type())) {
            return $configured;
        }

        $ready = array_values(array_filter(
            $configured,
            fn (string $locale): bool => ($draft = $this->draft($locale)) instanceof LegalDraft && $this->isPublishable($draft),
        ));

        if (! in_array($this->sourceLocale, $configured, true)) {
            return $ready === [] ? $configured : $ready;
        }

        return in_array($this->sourceLocale, $ready, true) ? $ready : [$this->sourceLocale];
    }

    /** This document's type, from the registry entry its key names. */
    public function type(): DocumentType
    {
        $basis = config("legal-consent.documents.{$this->key}.legal_basis");

        return DocumentType::fromLegalBasis(is_string($basis) ? $basis : 'contract');
    }

    /**
     * The locales that would block a release right now, each with its reason — so a release screen
     * can say WHY instead of just refusing.
     *
     * This is the readiness the admin overview arms its button from, and {@see
     * LegalDocumentReleaser} refuses over the same set. The two must not drift: a button armed
     * over a refusal is a control that answers with one.
     *
     * @param  list<string>  $locales
     * @return array<string, BlockingReason>
     */
    public function blockingLocales(array $locales): array
    {
        // THE SOURCE FIRST, BEFORE ANY QUESTION ABOUT DRAFTS. A document whose text does not come
        // from the draft store cannot be released from here at all, so every other reason is
        // beside the point — and one of them is actively misleading. With no drafts this used to
        // answer "no draft yet", which is true and useless: an operator reads it, writes a draft,
        // has it reviewed, watches the button go live, and meets the refusal. The reason that is
        // ACTIONABLE is the source, and it is also exactly what the releaser refuses over, so the
        // overview and the release now say the same sentence about the same state.
        if (DocumentSourceKind::isOutsideTheDraftStore($this->key)) {
            return array_fill_keys($locales, BlockingReason::NotDraftBacked);
        }

        $blocking = [];

        foreach ($locales as $locale) {
            $draft = $this->draft($locale);

            if (! $draft instanceof LegalDraft) {
                $blocking[$locale] = BlockingReason::NoDraft;

                continue;
            }

            // Review first, then staleness — a draft nobody ever reviewed is also "stale" (its
            // source_hash is null), and reporting that as "the source changed after this was
            // reviewed" would be a lie about a text that was never reviewed at all.
            if ($draft->review_state !== ReviewState::Reviewed) {
                $blocking[$locale] = BlockingReason::NotReviewed;

                continue;
            }

            if ($this->isStale($draft)) {
                $blocking[$locale] = BlockingReason::StaleTranslation;
            }
        }

        return $blocking;
    }

    /**
     * The same question for a whole set of locales, in ONE query rather than one per locale.
     *
     * The admin matrix is documents × locales and asked per cell: six documents in seven languages
     * cost 42 statements for something one statement answers, on a Livewire component that
     * re-renders on every filter click. A consumer measured it and folded the matrix itself.
     *
     * A locale with no draft answers false: there is no approved text waiting to go live. That is
     * the same answer the matrix composed by hand around the single-draft call, kept here so the two
     * cannot drift.
     *
     * @param  list<string>  $locales
     * @return array<string, bool>
     */
    public function unpublishedChanges(array $locales): array
    {
        $active = LegalDocument::model()::query()
            ->where('key', $this->key)
            ->whereIn('locale', $locales)
            ->where('is_active', true)
            ->pluck('content_hash', 'locale');

        $answer = [];

        foreach ($locales as $locale) {
            $draft = $this->draft($locale);

            $answer[$locale] = $draft instanceof LegalDraft && $active->get($locale) !== $draft->content_hash;
        }

        return $answer;
    }

    /** Approved text that is not live: the draft differs from the active published row. */
    public function hasUnpublishedChanges(LegalDraft $draft): bool
    {
        // Through the set variant, so a single cell and a whole matrix answer with one predicate.
        // It is the same one query this used to make on its own.
        return $this->unpublishedChanges([$draft->locale])[$draft->locale];
    }
}
