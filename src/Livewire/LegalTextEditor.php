<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use Pushery\LegalConsent\Contracts\LegalTextTranslator;
use Pushery\LegalConsent\Exceptions\TranslatorNotConfigured;
use Pushery\LegalConsent\Livewire\Concerns\AuthorizesLegalAdmin;
use Pushery\LegalConsent\Models\LegalDraft;
use Pushery\LegalConsent\Support\LegalDraftSet;
use Pushery\LegalConsent\Support\LegalDraftWriter;

/**
 * Edits ONE draft — one document key, one locale.
 *
 * The editor never writes bytes itself: every Save/Translate goes through {@see LegalDraftWriter},
 * which owns the invariant that a draft body is always sanitized pipeline output. That is what
 * keeps this screen — the app's first `{!! !!}` sink — from becoming a stored-XSS hole: the text
 * is sanitized on the way in, by the same allowlist the ledger hashes.
 *
 * Fail-closed behind {@see AuthorizesLegalAdmin} (404 with no ability configured).
 */
final class LegalTextEditor extends Component
{
    use AuthorizesLegalAdmin;

    public string $key = '';

    public string $locale = '';

    public string $body = '';

    public string $status = '';

    public function mount(string $key, string $locale): void
    {
        $this->key = $key;
        $this->locale = $locale;

        $draft = LegalDraftSet::for($key)->draft($locale);
        $this->body = $draft instanceof LegalDraft ? $draft->body : '';
    }

    public function save(): void
    {
        app(LegalDraftWriter::class)->save($this->key, $this->locale, $this->body, $this->actor());

        // A status message after a save, and after the two acts below — WCAG 4.1.3: an action that
        // changes the record must announce its result, not leave a screen reader in silence.
        $this->status = 'Saved. Review is required before this text can be published.';
    }

    public function translate(): void
    {
        $sourceLocale = $this->sourceLocale();

        if ($this->locale === $sourceLocale) {
            $this->status = 'The source locale is authored, not translated.';

            return;
        }

        $source = LegalDraftSet::for($this->key)->draft($sourceLocale);

        if (! $source instanceof LegalDraft) {
            $this->status = 'Write the source text first — there is nothing to translate from.';

            return;
        }

        try {
            $translated = app(LegalTextTranslator::class)->translate($source->body, $sourceLocale, $this->locale);
        } catch (TranslatorNotConfigured $e) {
            $this->status = $e->getMessage();

            return;
        }

        $draft = app(LegalDraftWriter::class)->applyTranslation($this->key, $this->locale, $translated, $source->content_hash, $this->actor());
        $this->body = $draft->body;
        $this->status = 'Machine-translated. A human must review it before it can be published.';
    }

    public function markReviewed(): void
    {
        app(LegalDraftWriter::class)->markReviewed($this->key, $this->locale, $this->actor());
        $this->status = 'Marked reviewed. This text is now publishable.';
    }

    public function render(): View
    {
        $set = LegalDraftSet::for($this->key);
        $draft = $set->draft($this->locale);

        return view('legal-consent::livewire.legal-text-editor', [
            'sourceLocale' => $this->sourceLocale(),
            'isSource' => $this->locale === $this->sourceLocale(),
            'reviewState' => $draft?->review_state->value,
            'stale' => $draft instanceof LegalDraft && $set->isStale($draft),
            // The preview renders exactly what a publish would freeze — the already-sanitized body,
            // not a re-render — so it is a true fixpoint of what the subject will see.
            'preview' => $draft instanceof LegalDraft ? $draft->body : '',
        ]);
    }

    private function sourceLocale(): string
    {
        $locale = config('legal-consent.default_locale');

        return is_string($locale) ? $locale : 'de';
    }

    /** The acting user's identifier, handed to the writer's events for the consumer's audit log. */
    private function actor(): ?string
    {
        $id = auth()->user()?->getAuthIdentifier();

        return is_int($id) || is_string($id) ? (string) $id : null;
    }
}
