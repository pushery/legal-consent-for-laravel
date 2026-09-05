<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Pushery\LegalConsent\Content\PublishedDocument;
use Pushery\LegalConsent\Contracts\ConsentManager;
use Pushery\LegalConsent\Enums\ConsentMethod;
use Pushery\LegalConsent\Exceptions\LegalDocumentNotFound;
use Pushery\LegalConsent\Exceptions\NotGrantableException;
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
 * `withdraw()`, `object()`, `terminate()` and — only when you switch it on — `grant()`.
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
 * `$allowGrant` is the odd one out and it defaults to FALSE, unlike the other two. Granting is
 * the only direction here that WRITES an assertion that the subject agreed; the rest remove or
 * contest one. A proof row saying "they agreed", created from an endpoint the consumer never
 * rendered a control for, is the wrong default for a package whose entire product is proof — so
 * this one is asked for rather than assumed. Turn it on and the screen becomes symmetric: a
 * voluntary consent can be given again after it was withdrawn, which is the point of Art. 7(3)
 * being about *ease*, not about a one-way door.
 *
 * All three are `#[Locked]`: a switch the browser could flip back is not a switch.
 */
final class ConsentSettings extends Component
{
    use AnnouncesStatus;
    use RefusesUnavailableTransitions;

    /**
     * The language version every transition on this screen resolves against.
     *
     * #[Locked] because it decides WHICH version is resolved and frozen: the manager reads it to
     * find the active document, and the ledger row keeps that document's locale, version,
     * content_hash and acceptance sentence as proof. Left writable, a subject could switch it at
     * click time and have an append-only row assert agreement to — or withdrawal of — a text they
     * were never shown. No view binds it; it is set once at mount by the embedding screen.
     */
    #[Locked]
    public string $locale = '';

    /**
     * ⚠️ THE INITIALIZERS BELOW ARE NOT THE POLICY, AND EDITING ONE CHANGES NOTHING.
     *
     * The value in effect always comes from somewhere else: on the first request from `mount()`'s
     * parameter default, and on every request after that from the checksummed snapshot, because
     * `#[Locked]` properties are restored rather than re-mounted. So the assignment here is only
     * what PHP requires of a typed property before anything may read it -- leave it off and an
     * access before `mount()` is a fatal, not a false.
     *
     * Measured: flipping every one of these leaves the whole suite green, while flipping the
     * matching `mount()` default reddens it immediately. That asymmetry is the point of this note.
     * A capability switched off here would look switched off in review and stay on in production.
     */
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

    /** Whether the grant endpoint exists on this instance. Off by default — see the class docblock. */
    #[Locked]
    public bool $allowGrant = false;

    public function mount(
        ?string $locale = null,
        bool $allowObjection = true,
        bool $allowTermination = true,
        bool $allowGrant = false,
    ): void {
        $this->locale = $locale ?? app()->getLocale();
        $this->allowObjection = $allowObjection;
        $this->allowTermination = $allowTermination;
        $this->allowGrant = $allowGrant;
    }

    /**
     * Give a voluntary consent that is not currently held.
     *
     * The counterpart the screen was missing: withdrawal was reachable, granting was not, so a
     * subject who withdrew — or who never ticked the box at registration — had no way back. In an
     * application that has no registration form at all, there was no way to give one in the first
     * place.
     *
     * It refuses anything that is not a voluntary consent, and that refusal is the point rather
     * than a safety net: a contract or an acknowledgement is accepted where its full text is
     * presented, because the acceptance must be informed (Art. 7(1)), and a toggle beside a title
     * is not a presentation of a contract.
     */
    public function grant(string $key): void
    {
        abort_unless($this->allowGrant, 404);

        $subject = $this->subject();

        if (! $subject instanceof Model) {
            return;
        }

        $this->guardedTransition(function () use ($subject, $key): void {
            $document = app(ConsentManager::class)->published($key, $this->locale);

            // No published version is the same client error the manager raises for the other three
            // transitions, so it takes the same route and answers 404 rather than 500.
            if (! $document instanceof PublishedDocument) {
                throw LegalDocumentNotFound::forSource($key, $this->locale, LegalDocumentNotFound::PUBLISHED_LOOKUP);
            }

            if (! $document->type->requiresExplicitOptin()) {
                throw NotGrantableException::for($key, $document->type);
            }

            app(ConsentManager::class)->accept(
                $subject,
                $key,
                ConsentContext::fromRequest(request(), ConsentMethod::SettingsToggle),
                $this->locale,
            );
        });

        $this->setStatus((string) __('legal-consent::ui.granted_confirmation'));
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
