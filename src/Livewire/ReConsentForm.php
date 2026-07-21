<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Pushery\LegalConsent\Contracts\ConsentManager;
use Pushery\LegalConsent\Enums\ConsentMethod;
use Pushery\LegalConsent\Exceptions\DocumentChangedException;
use Pushery\LegalConsent\Support\ConsentContext;
use Pushery\LegalConsent\Support\DefaultConsentManager;

/**
 * Opt-in reactive re-consent form: lists the documents the subject still owes and records the
 * ticked ones on submit. A nested Livewire component — embed with
 * <livewire:legal-consent.reconsent-form />. Only registers when livewire/livewire is
 * installed; the plain checkbox stub covers the headless case. Requires an authenticated
 * Eloquent subject (auth()->user()).
 */
final class ReConsentForm extends Component
{
    /** @var array<string, bool> */
    public array $accept = [];

    /**
     * The content hash of each shown document, captured at render and persisted across the Livewire
     * request. Passed at submit so acceptance is guarded against a version released mid-session
     * (accept-time TOCTOU).
     *
     * #[Locked] because an unlocked public property is CLIENT-WRITABLE: a subject could rewrite the
     * hash to match the new version and defeat the very guard that exists to stop them accepting a
     * text they never saw. The value is not a secret (a content_hash is derivable from the public
     * text) — the point is that only the SERVER may set it.
     *
     * @var array<string, string>
     */
    #[Locked]
    public array $hashes = [];

    /**
     * Locked too: the locale decides WHICH version is resolved and frozen. Left writable, a client
     * could switch it at submit and record acceptance of a different language's document than the
     * one rendered — the mirror image of the hash guard.
     */
    #[Locked]
    public string $locale = '';

    /**
     * A confirmation of the last submit, for the view's live region. When the outstanding list
     * empties on submit the whole <form> is replaced by the "all current" message — inserted WITH
     * its content, so a screen reader never announces it, and the focused submit button vanishes so
     * focus drops to <body> (WCAG 4.1.3 + 2.4.3). Announcing here, in an always-present region the
     * view moves focus to, is the confirmation — mirroring the hardened ConsentSettings component.
     */
    public string $status = '';

    /**
     * How the acceptance was obtained. This lands in the ledger as proof of HOW a subject agreed,
     * so it must describe the surface it actually happened on: this component is a re-consent gate
     * by default, and only a settings-page embed is a settings toggle. Recording every acceptance
     * as a settings toggle would put a provenance in the ledger that never happened.
     *
     * #[Locked] for exactly that reason: this is PROOF, and proof the client can rewrite is not
     * proof. It is set once at mount by the embedding screen.
     */
    #[Locked]
    public ConsentMethod $method = ConsentMethod::ReConsentGate;

    public function mount(?string $locale = null, ConsentMethod $method = ConsentMethod::ReConsentGate): void
    {
        $this->locale = $locale ?? app()->getLocale();
        $this->method = $method;
    }

    public function submit(): void
    {
        $subject = $this->subject();

        if (! $subject instanceof Model) {
            return;
        }

        $manager = app(ConsentManager::class);
        $recorded = 0;

        foreach ($manager->outstanding($subject, $this->locale) as $document) {
            if (($this->accept[$document->key] ?? false) === true) {
                try {
                    // Pass the hash captured at RENDER, not the live one: a version released between
                    // render and this submit must be caught, not silently frozen (Art. 7(1)).
                    $manager->accept($subject, $document->key, ConsentContext::fromRequest(request(), $this->method), $this->locale, $this->hashes[$document->key] ?? null);
                    $recorded++;
                } catch (DocumentChangedException) {
                    // The subject would freeze text they never saw. Clear the stale ticks and ask them
                    // to review — the re-render re-shows the current version and re-captures its hash,
                    // so a conscious re-acceptance records the version actually read.
                    $this->accept = [];
                    $this->status = (string) __('legal-consent::ui.reconsent_changed');

                    return;
                }
            }
        }

        if ($recorded > 0) {
            $this->status = (string) __('legal-consent::ui.reconsent_recorded');

            // A re-consent GATE that is now fully cleared returns the subject to where the
            // enforcement middleware intercepted them (redirect()->guest stashed it), falling back
            // to the configured home. Opt-in, and never for a settings-page embed, so the in-place
            // "all current" confirmation existing consumers rely on is unchanged by default.
            if ($this->shouldReturnToIntended() && $manager->outstanding($subject, $this->locale)->isEmpty()) {
                $home = config('legal-consent.routes.home', '/');
                $fallback = is_string($home) && $home !== '' ? $home : '/';

                // Read the URL the enforcement middleware stashed (redirect()->guest set url.intended)
                // straight from the session, not via redirect()->intended(): inside a Livewire
                // component redirect() is Livewire's own capturing redirector, which has no such value.
                $intended = session()->pull('url.intended', $fallback);

                $this->redirect(is_string($intended) && $intended !== '' ? $intended : $fallback);
            }
        }
    }

    private function shouldReturnToIntended(): bool
    {
        return $this->method === ConsentMethod::ReConsentGate
            && config('legal-consent.routes.return_to_intended', false) === true;
    }

    public function object(string $key): void
    {
        $subject = $this->subject();

        if ($subject instanceof Model) {
            app(ConsentManager::class)->object($subject, $key, ConsentContext::fromRequest(request(), $this->method), $this->locale);
        }
    }

    public function terminate(string $key): void
    {
        $subject = $this->subject();

        if ($subject instanceof Model) {
            app(ConsentManager::class)->terminate($subject, $key, ConsentContext::fromRequest(request(), $this->method), $this->locale);
        }
    }

    public function render(): View
    {
        $subject = $this->subject();

        $pending = $subject instanceof Model
            ? app(ConsentManager::class)->outstanding($subject, $this->locale)
            : new Collection;

        // Capture the content hash of each shown document into component state (persisted across the
        // Livewire request), so submit() can pass what the subject ACTUALLY saw — not a value
        // re-fetched live, which would make the accept-time TOCTOU guard vacuous.
        $this->hashes = [];

        foreach ($pending as $document) {
            // The same fingerprint accept() compares against — body hash folded with the acceptance
            // sentence, because that sentence is the only document text this form actually shows.
            $this->hashes[$document->key] = DefaultConsentManager::acceptanceFingerprint($document);
        }

        return view('legal-consent::livewire.reconsent-form', ['pending' => $pending]);
    }

    private function subject(): ?Model
    {
        $user = auth()->user();

        return $user instanceof Model ? $user : null;
    }
}
