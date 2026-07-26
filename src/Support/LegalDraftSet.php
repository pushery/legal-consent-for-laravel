<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Support\Collection;
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
 * the source stales nothing. Accepted caveat: that canonicalisation's `\s` is not Unicode-aware,
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
            LegalDraft::query()->where('key', $key)->get(),
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

    /** The gate's predicate: a human signed off on these exact bytes, and the source has not moved. */
    public function isPublishable(LegalDraft $draft): bool
    {
        return $draft->review_state === ReviewState::Reviewed && ! $this->isStale($draft);
    }

    /**
     * The locales that would block a release right now, each with its reason — so a release screen
     * can say WHY instead of just refusing.
     *
     * @param  list<string>  $locales
     * @return array<string, string>
     */
    public function blockingLocales(array $locales): array
    {
        $blocking = [];

        foreach ($locales as $locale) {
            $draft = $this->draft($locale);

            if (! $draft instanceof LegalDraft) {
                $blocking[$locale] = 'no draft has been written';

                continue;
            }

            // Review first, then staleness — a draft nobody ever reviewed is also "stale" (its
            // source_hash is null), and reporting that as "the source changed after this was
            // reviewed" would be a lie about a text that was never reviewed at all.
            if ($draft->review_state !== ReviewState::Reviewed) {
                $blocking[$locale] = 'not reviewed by a human yet';

                continue;
            }

            if ($this->isStale($draft)) {
                $blocking[$locale] = 'the source text changed after this translation was reviewed';
            }
        }

        return $blocking;
    }

    /** Approved text that is not live: the draft differs from the active published row. */
    public function hasUnpublishedChanges(LegalDraft $draft): bool
    {
        $activeHash = LegalDocument::query()
            ->where('key', $this->key)
            ->where('locale', $draft->locale)
            ->where('is_active', true)
            ->value('content_hash');

        return $activeHash !== $draft->content_hash;
    }
}
