<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Pushery\LegalConsent\Contracts\ConsentManager;
use Pushery\LegalConsent\Enums\ConsentMethod;
use Pushery\LegalConsent\Livewire\Concerns\AnnouncesStatus;
use Pushery\LegalConsent\Livewire\Concerns\RefusesUnavailableTransitions;
use Pushery\LegalConsent\Support\ConsentContext;
use Pushery\LegalConsent\Support\ConsentPresenter;

/**
 * Opt-in reactive "My consents" screen (Art. 7(3): withdrawal as easy as it was given).
 * A nested Livewire component — embed it with <livewire:legal-consent.consent-settings />.
 * Only ships/registers when livewire/livewire is installed; the plain Blade stub covers the
 * headless case. Requires an authenticated Eloquent subject (auth()->user()).
 *
 * ⚠️ EVERY PUBLIC METHOD HERE IS A REACHABLE ENDPOINT ONCE THE COMPONENT IS EMBEDDED —
 * Livewire dispatches to it whether or not your template renders a control for it. Removing a
 * button from a published view therefore switches nothing off. Three transitions are exposed:
 * `withdraw()`, `object()` and `terminate()`.
 *
 * Two protections, and they answer different questions:
 *
 *  - The MANAGER refuses a transition the document's class cannot carry (a termination against a
 *    privacy notice, an objection against a consent). That keeps the append-only ledger free of
 *    rows asserting states that do not legally exist — which cannot be corrected afterwards and
 *    which every later reader takes as proof.
 *  - `$allowObjection` / `$allowTermination` let YOU decide whether the endpoints exist at all.
 *    They are on by default, because the package's own contract offers all three. Turn one off
 *    when your product has no answer to it: an app that only offers withdrawal does not want a
 *    reachable "terminate" that nothing downstream reacts to.
 *
 * ```blade
 * <livewire:legal-consent.consent-settings :allow-objection="false" :allow-termination="false" />
 * ```
 *
 * Both are `#[Locked]`: a switch the browser could flip back is not a switch.
 */
final class ConsentSettings extends Component
{
    use AnnouncesStatus;
    use RefusesUnavailableTransitions;

    public string $locale = '';

    /**
     * Whether the objection endpoint exists on this instance. Locked, because a value the client
     * can send back is not a permission — Livewire hydrates public properties from the payload
     * unless told otherwise, so an unlocked flag would be a suggestion, not a switch.
     */
    #[Locked]
    public bool $allowObjection = true;

    /** Whether the termination endpoint exists on this instance. Locked, for the reason above. */
    #[Locked]
    public bool $allowTermination = true;

    public function mount(?string $locale = null, bool $allowObjection = true, bool $allowTermination = true): void
    {
        $this->locale = $locale ?? app()->getLocale();
        $this->allowObjection = $allowObjection;
        $this->allowTermination = $allowTermination;
    }

    public function withdraw(string $key): void
    {
        $subject = $this->subject();

        if ($subject instanceof Model) {
            $this->guardedTransition(fn () => app(ConsentManager::class)->withdraw(
                $subject,
                $key,
                ConsentContext::fromRequest(request(), ConsentMethod::SettingsToggle),
                $this->locale,
            ));

            $this->setStatus((string) __('legal-consent::ui.withdrawn_confirmation'));
        }
    }

    public function object(string $key): void
    {
        // 404, not 403: a disabled action is one this instance does not have, and saying "you may
        // not" would confirm it exists. Same reasoning the admin screens use for `admin.ability`.
        abort_unless($this->allowObjection, 404);

        $subject = $this->subject();

        if ($subject instanceof Model) {
            $this->guardedTransition(fn () => app(ConsentManager::class)->object(
                $subject,
                $key,
                ConsentContext::fromRequest(request(), ConsentMethod::SettingsToggle),
                $this->locale,
            ));
        }
    }

    public function terminate(string $key): void
    {
        abort_unless($this->allowTermination, 404);

        $subject = $this->subject();

        if ($subject instanceof Model) {
            $this->guardedTransition(fn () => app(ConsentManager::class)->terminate(
                $subject,
                $key,
                ConsentContext::fromRequest(request(), ConsentMethod::SettingsToggle),
                $this->locale,
            ));
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
