<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;
use Pushery\LegalConsent\Contracts\ConsentManager;
use Pushery\LegalConsent\Enums\ConsentMethod;
use Pushery\LegalConsent\Support\ConsentContext;

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

    public string $locale = '';

    /**
     * How the acceptance was obtained. This lands in the ledger as proof of HOW a subject agreed,
     * so it must describe the surface it actually happened on: this component is a re-consent gate
     * by default, and only a settings-page embed is a settings toggle. Recording every acceptance
     * as a settings toggle would put a provenance in the ledger that never happened.
     */
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

        foreach ($manager->outstanding($subject, $this->locale) as $document) {
            if (($this->accept[$document->key] ?? false) === true) {
                $manager->accept($subject, $document->key, ConsentContext::fromRequest(request(), $this->method), $this->locale);
            }
        }
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

        return view('legal-consent::livewire.reconsent-form', ['pending' => $pending]);
    }

    private function subject(): ?Model
    {
        $user = auth()->user();

        return $user instanceof Model ? $user : null;
    }
}
