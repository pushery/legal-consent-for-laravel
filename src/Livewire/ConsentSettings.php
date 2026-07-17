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

    /**
     * A confirmation of the last action, for the view's live region. After a withdrawal the row's
     * button vanishes on re-render, so a sighted user gets weak feedback and a screen-reader user
     * gets nothing at all confirming the (irreversible, append-only) act (WCAG 4.1.3). Announcing
     * it here is the confirmation, and the view moves focus to it so focus does not drop to <body>
     * when the button it was on disappears (WCAG 2.4.3).
     */
    public string $status = '';

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

            $this->status = (string) __('legal-consent::ui.withdrawn_confirmation');
        }
    }

    public function object(string $key): void
    {
        $subject = $this->subject();

        if ($subject instanceof Model) {
            app(ConsentManager::class)->object(
                $subject,
                $key,
                ConsentContext::fromRequest(request(), ConsentMethod::SettingsToggle),
                $this->locale,
            );
        }
    }

    public function terminate(string $key): void
    {
        $subject = $this->subject();

        if ($subject instanceof Model) {
            app(ConsentManager::class)->terminate(
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
