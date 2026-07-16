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

    public function mount(?string $locale = null): void
    {
        $this->locale = $locale ?? app()->getLocale();
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
                $manager->accept($subject, $document->key, ConsentContext::fromRequest(request(), ConsentMethod::SettingsToggle), $this->locale);
            }
        }
    }

    public function object(string $key): void
    {
        $subject = $this->subject();

        if ($subject instanceof Model) {
            app(ConsentManager::class)->object($subject, $key, ConsentContext::fromRequest(request(), ConsentMethod::SettingsToggle), $this->locale);
        }
    }

    public function terminate(string $key): void
    {
        $subject = $this->subject();

        if ($subject instanceof Model) {
            app(ConsentManager::class)->terminate($subject, $key, ConsentContext::fromRequest(request(), ConsentMethod::SettingsToggle), $this->locale);
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
