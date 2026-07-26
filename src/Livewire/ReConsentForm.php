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
use Pushery\LegalConsent\Livewire\Concerns\AnnouncesStatus;
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
    use AnnouncesStatus;

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
                if (! array_key_exists($document->key, $this->hashes)) {
                    // Fail closed: this document is ticked but carries no render-time hash — it was
                    // ticked while momentarily not outstanding, so render() never captured one. Passing
                    // null would skip the accept-time guard entirely and freeze a version whose text was
                    // never rendered. Take the same path as a changed document: clear the stale ticks
                    // and ask the subject to review the version they are actually shown.
                    $this->accept = [];
                    $this->setStatus((string) __('legal-consent::ui.reconsent_changed'));

                    return;
                }

                try {
                    // Pass the hash captured at RENDER, not the live one: a version released between
                    // render and this submit must be caught, not silently frozen (Art. 7(1)).
                    $manager->accept($subject, $document->key, ConsentContext::fromRequest(request(), $this->method), $this->locale, $this->hashes[$document->key]);
                    $recorded++;
                } catch (DocumentChangedException) {
                    // The subject would freeze text they never saw. Clear the stale ticks and ask them
                    // to review — the re-render re-shows the current version and re-captures its hash,
                    // so a conscious re-acceptance records the version actually read.
                    $this->accept = [];
                    $this->setStatus((string) __('legal-consent::ui.reconsent_changed'));

                    return;
                }
            }
        }

        if ($recorded > 0) {
            $this->setStatus((string) __('legal-consent::ui.reconsent_recorded'));

            // A re-consent GATE that is now fully cleared returns the subject to where the
            // enforcement middleware intercepted them (redirect()->guest stashed it), falling back
            // to the configured home. Opt-in, and never for a settings-page embed, so the in-place
            // "all current" confirmation existing consumers rely on is unchanged by default.
            if ($this->shouldReturnToIntended() && $manager->outstanding($subject, $this->locale)->isEmpty()) {
                $home = config('legal-consent.routes.home', '/');
                $fallback = is_string($home) && $home !== '' ? $home : '/';

                // Read the URL the enforcement middleware stashed (redirect()->guest set url.intended)
                // straight from the session so the raw value can be range-checked before use. It is
                // derived from redirect()->guest, which on a non-GET or pre-routing request falls back
                // to the Referer header — an attacker can poison it with an external origin. Returning
                // there right after a trust-establishing legal flow is an ideal phishing hand-off, so a
                // target that is not same-origin is dropped for the home route.
                $intended = session()->pull('url.intended', $fallback);
                $target = is_string($intended) && $this->isSameOrigin($intended) ? $intended : $fallback;

                $this->redirect($target);
            }
        } else {
            // Nothing was ticked: with no status the submit reads as a dead no-op. Announce a prompt
            // so the subject learns their click registered and that a box still needs ticking.
            $this->setStatus((string) __('legal-consent::ui.reconsent_none_selected'));
        }
    }

    private function shouldReturnToIntended(): bool
    {
        return $this->method === ConsentMethod::ReConsentGate
            && config('legal-consent.routes.return_to_intended', false) === true;
    }

    /**
     * A redirect target is safe only when it stays on this application's origin: a relative path
     * (no host), or an absolute URL whose host matches the current request. A protocol-relative
     * '//host/…' or an external absolute URL — a Referer-poisoned url.intended — is unsafe.
     */
    private function isSameOrigin(string $target): bool
    {
        // Strip the leading control chars / whitespace a browser ignores before resolving a URL,
        // then reject the shapes parse_url does NOT read as an authority but a browser does: a
        // backslash (browsers treat `\` as `/`, so `/\evil` becomes `//evil`) and a protocol-relative
        // `//host`. Do not trust the caller to have pre-sanitized the value — honor the contract here.
        $target = ltrim($target, " \t\n\r\0\x0B");

        if ($target === '' || str_contains($target, '\\') || str_starts_with($target, '//')) {
            return false;
        }

        $host = parse_url($target, PHP_URL_HOST);

        // No host → accept only a rooted relative path (`/…`); a scheme like `mailto:`/`tel:`, a bare
        // word or a fragment is dropped. Otherwise the host must match this request's.
        return $host === null ? str_starts_with($target, '/') : $host === request()->getHost();
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
