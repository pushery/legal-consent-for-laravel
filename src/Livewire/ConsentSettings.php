<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;
use Pushery\LegalConsent\Contracts\ConsentManager;
use Pushery\LegalConsent\Enums\ConsentMethod;
use Pushery\LegalConsent\Support\ConsentContext;
use Pushery\LegalConsent\Support\ConsentPresenter;

/**
 * Opt-in reactive "My consents" screen (Art. 7(3): withdrawal as easy as it was given).
 * A nested Livewire component — embed it with <livewire:legal-consent.consent-settings />.
 * Only ships/registers when livewire/livewire is installed; the plain Blade stub covers the
 * headless case. Requires an authenticated Eloquent subject (auth()->user()).
 */
final class ConsentSettings extends Component
{
    public string $locale = '';

    public function mount(?string $locale = null): void
    {
        $this->locale = $locale ?? app()->getLocale();
    }

    public function withdraw(string $key): void
    {
        $subject = $this->subject();

        if ($subject instanceof Model) {
            app(ConsentManager::class)->withdraw(
                $subject,
                $key,
                ConsentContext::fromRequest(request(), ConsentMethod::SettingsToggle),
                $this->locale,
            );
        }
    }

    public function render(): View
    {
        $subject = $this->subject();

        $groups = $subject instanceof Model
            ? app(ConsentPresenter::class)->settingsFor($subject, $this->locale)
            : ['contracts' => [], 'acknowledgements' => [], 'consents' => []];

        return view('legal-consent::livewire.consent-settings', $groups);
    }

    private function subject(): ?Model
    {
        $user = auth()->user();

        return $user instanceof Model ? $user : null;
    }
}
