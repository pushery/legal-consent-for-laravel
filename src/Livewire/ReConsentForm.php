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
use Pushery\LegalConsent\Exceptions\LegalDocumentNotFound;
use Pushery\LegalConsent\Livewire\Concerns\AnnouncesStatus;
use Pushery\LegalConsent\Livewire\Concerns\RefusesUnavailableTransitions;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Support\ConsentContext;
use Pushery\LegalConsent\Support\DefaultConsentManager;
use Pushery\LegalConsent\Support\DocumentUrlResolver;

/**
 * Opt-in reactive re-consent form: lists the documents the subject still owes and records the
 * ticked ones on submit. A nested Livewire component — embed with
 * <livewire:legal-consent.reconsent-form />. Only registers when livewire/livewire is
 * installed; the plain checkbox stub covers the headless case. Requires an authenticated
 * Eloquent subject (auth()->user()).
 *
 * ⚠️ EVERY PUBLIC METHOD HERE IS A REACHABLE ENDPOINT ONCE THE COMPONENT IS EMBEDDED —
 * Livewire dispatches to it whether or not your template renders a control for it, so deleting a
 * button from a published view switches nothing off. Besides `submit()` this exposes `object()`
 * and `terminate()`, exactly like the settings screen, and `$allowObjection` /
 * `$allowTermination` decide whether those endpoints exist at all:
 *
 * ```blade
 * <livewire:legal-consent.reconsent-form :allow-objection="false" :allow-termination="false" />
 * ```
 *
 * On by default, because the package's own contract offers all three. Turn one off when your
 * product has no answer to it. Both are `#[Locked]`: a switch the browser could flip back is not
 * a switch. This is not a security boundary being added — the manager already refuses a
 * transition the document's class cannot carry — it is the product decision the settings
 * component offered and this one did not.
 */
final class ReConsentForm extends Component
{
    use AnnouncesStatus;
    use RefusesUnavailableTransitions;

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
     *
     * Mounting with `answersGateQuestion` asks for the third behavior — the gate's own question
     * — and leaves this at `ReConsentGate`, which is then only a fallback: see
     * {@see $answersGateQuestion} and {@see questionFor()}.
     */
    #[Locked]
    public ConsentMethod $method = ConsentMethod::ReConsentGate;

    /**
     * Whether this mount answers the question the GATE asks, rather than one of the two halves.
     *
     * `EnsureLegalConsent` holds on the UNION of `outstanding()` and `firstAcceptance()` once
     * `gate.first_use` is on, while a mount that names a method answers one of the two. A host
     * mounting the consent route once — the shape `routes.consent_name` describes, one name and
     * one route — therefore sent every first-use subject to a form rendering zero documents while
     * the gate kept holding the next request. The doctor already named the outcome: a dead end,
     * not a loop.
     *
     * ⚠️ THIS IS A BOOLEAN, AND A NULLABLE `$method` WAS TRIED FIRST AND IS BROKEN TWICE OVER.
     * Measured on this component: a `#[Locked]` typed property mounted as null reads back as null
     * on the mount request and as the property's own PHP default on EVERY request after it — so
     * the form rendered the union, the subject ticked both boxes, and `submit()`, a later
     * request, was back at `ReConsentGate` and iterated only the changed half. One acceptance
     * dropped, success message on screen. And it cannot even be mounted: Livewire assigns a mount
     * argument onto the public property of the same name, so `['method' => null]` is a TypeError
     * against a non-nullable enum. A boolean round-trips, and it keeps `$method` non-nullable so
     * nothing downstream has to ask whether the provenance exists.
     *
     * `#[Locked]` for the same reason as `$method`: it decides what goes into an append-only
     * ledger row, so it is not something a browser may send back.
     */
    #[Locked]
    public bool $answersGateQuestion = false;

    /**
     * Which question each pending key came from, for THIS request only.
     *
     * Private, so Livewire never serializes it and no client can send one back — the value it
     * decides is the ledger's `method` column. Rebuilt by {@see pendingFor()} on every request,
     * which both `render()` and `submit()` call, so it is never read across a hydration.
     *
     * @var array<string, ConsentMethod>
     */
    private array $questionByKey = [];

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

    /**
     * `$answersGateQuestion` is last on purpose: appending it leaves every existing positional
     * call untouched. It is also a parameter of its own rather than a null `$method`, and that is
     * forced rather than chosen — Livewire assigns a mount argument straight onto the public
     * property of the same name, so `['method' => null]` is a TypeError against a non-nullable
     * enum before `mount()` ever runs. Making the property nullable to accept it is the trap the
     * property's own docblock records.
     */
    public function mount(
        ?string $locale = null,
        ConsentMethod $method = ConsentMethod::ReConsentGate,
        bool $allowObjection = true,
        bool $allowTermination = true,
        bool $answersGateQuestion = false,
    ): void {
        $this->locale = $locale ?? app()->getLocale();
        // The gate question keeps a concrete `$method` anyway, so every path that only needs A
        // provenance — the intended-return check, a transition on a holding the subject already
        // has — carries on reading it without a null test.
        $this->answersGateQuestion = $answersGateQuestion;
        $this->method = $method;
        $this->allowObjection = $allowObjection;
        $this->allowTermination = $allowTermination;
    }

    public function submit(): void
    {
        $subject = $this->subject();

        if (! $subject instanceof Model) {
            return;
        }

        $manager = app(ConsentManager::class);
        $recorded = 0;

        foreach ($this->pendingFor($subject) as $document) {
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
                    $manager->accept($subject, $document->key, ConsentContext::fromRequest(request(), $this->questionFor($document->key)), $this->locale, $this->hashes[$document->key]);
                    $recorded++;
                } catch (DocumentChangedException|LegalDocumentNotFound) {
                    // The subject would freeze text they never saw. Clear the stale ticks and ask them
                    // to review — the re-render re-shows the current version and re-captures its hash,
                    // so a conscious re-acceptance records the version actually read.
                    //
                    // LegalDocumentNotFound takes the SAME path, and not for symmetry: it means the
                    // document stopped being published between the render and this click, which is
                    // the same event as "it changed" from the subject's side. The window is not a
                    // race measured in microseconds — `outstanding()` is served from the
                    // enforceable-document cache (default TTL 60s) while `accept()` re-resolves
                    // against the database, so an out-of-band unpublish leaves a minute in which
                    // this button is the ordinary thing to click.
                    //
                    // Deliberately NOT the 404 the other transitions answer with. Those are single
                    // actions; this loop may already have recorded rows into an append-only ledger
                    // before it reaches the missing document, and aborting the response there would
                    // leave the subject on an error page with no idea which of their ticks took
                    // effect. Clearing and re-rendering shows them exactly what is still owed.
                    $this->accept = [];
                    $this->setStatus((string) __('legal-consent::ui.reconsent_changed'));

                    return;
                }
            }
        }

        if ($recorded > 0) {
            // A tick is good for the ROUND it was made in, never beyond it — the same line both
            // failure paths above already run. Kept, it survives into a round where a new major
            // version is outstanding under the same key, and accepts that one silently: the subject
            // ticked v1 and the ledger records v2. The accept-time hash guard cannot see it, because
            // render() has meanwhile re-captured the new version's fingerprint — that guard answers
            // "did the version change between render and submit", not "is this tick older than the
            // version". Append-only, so the row cannot be corrected afterwards.
            $this->accept = [];

            $this->setStatus((string) __('legal-consent::ui.reconsent_recorded'));

            // A re-consent GATE that is now fully cleared returns the subject to where the
            // enforcement middleware intercepted them (redirect()->guest stashed it), falling back
            // to the configured home. Opt-in, and never for a settings-page embed, so the in-place
            // "all current" confirmation existing consumers rely on is unchanged by default.
            if ($this->shouldReturnToIntended() && $this->pendingFor($subject)->isEmpty()) {
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
        // BOTH gate surfaces, not just the re-consent one. A first-use interstitial interrupts a
        // navigation exactly the way the re-consent gate does — the subject was going somewhere and
        // was stopped on the way. Leaving it out would silently drop the intended destination for
        // every OAuth application, which is the defect this seam was built to fix in the first
        // place. A settings toggle is deliberately not on this list: nobody was on their way
        // anywhere when they opened their own settings page.
        // A gate-question mount needs no entry of its own: it keeps `$method` at `ReConsentGate`,
        // which is already on this list, and by construction that subject was stopped on their way
        // somewhere.
        return in_array($this->method, [ConsentMethod::ReConsentGate, ConsentMethod::FirstUseGate], true)
            && config('legal-consent.routes.return_to_intended', false) === true;
    }

    /**
     * A redirect target is safe only when it stays on this application's origin: a relative path
     * (no host), or an absolute URL whose host matches the current request. A protocol-relative
     * '//host/…' or an external absolute URL — a Referer-poisoned url.intended — is unsafe.
     */
    private function isSameOrigin(string $target): bool
    {
        // Read the target the way the BROWSER will read it, not the way parse_url does — the check
        // is worthless anywhere the two disagree, and they disagree on control characters.
        //
        // The URL parser removes U+0009, U+000A and U+000D from ANY position before it parses
        // (WHATWG URL, "URL parsing"), so `/<TAB>/evil.example` is fetched as `//evil.example` — the
        // protocol-relative shape the next line exists to reject. Stripping them only at the front
        // judged a string no browser ever navigates to. The other two the old list named — NUL and
        // the vertical tab — keep being trimmed at the front only, because that is what the parser
        // does with them: stripped at the edges, ordinary path bytes in the middle. Any other
        // leading control byte simply fails the rooted-path test below, which is the safe direction.
        //
        // Nothing is handed on from here: the raw value is what gets redirected to, and the browser
        // performs this same removal itself. This function only has to judge the same string.
        $target = ltrim(str_replace(["\t", "\n", "\r"], '', $target), " \0\x0B");

        // The same disagreement, one layer down: parse_url reads neither of these as an authority
        // and a browser reads both — a backslash (`\` is treated as `/`, so `/\evil` becomes
        // `//evil`) and the protocol-relative `//host`. Do not trust the caller to have
        // pre-sanitized the value — honor the contract here.

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
        // 404, not 403: a disabled action is one this instance does not have, and saying "you may
        // not" would confirm it exists. Same reasoning, and the same two flags, as ConsentSettings.
        abort_unless($this->allowObjection, 404);

        $subject = $this->subject();

        if ($subject instanceof Model) {
            $this->guardedTransition(fn () => app(ConsentManager::class)->object($subject, $key, ConsentContext::fromRequest(request(), $this->questionFor($key)), $this->locale));

            // The status is not decoration on this action. It writes an APPEND-ONLY ledger row, and
            // the control that triggered it is usually gone from the next render — so with nothing
            // announced, the honest reading of the screen is that nothing happened. The natural
            // response is a second click, and a second click writes a second row that cannot be
            // taken back. WCAG 4.1.3 is the same requirement from the other side.

            $this->setStatus((string) __('legal-consent::ui.objected_confirmation'));
        }
    }

    public function terminate(string $key): void
    {
        abort_unless($this->allowTermination, 404);

        $subject = $this->subject();

        if ($subject instanceof Model) {
            $this->guardedTransition(fn () => app(ConsentManager::class)->terminate($subject, $key, ConsentContext::fromRequest(request(), $this->questionFor($key)), $this->locale));

            // Same reason as object() above: an irreversible write nobody is told about.
            $this->setStatus((string) __('legal-consent::ui.terminated_confirmation'));
        }
    }

    /**
     * The documents THIS MOUNT is asking about.
     *
     * A first-use interstitial and a re-consent gate ask different questions, and the method the
     * mount declares is where they separate — the same value that will end up in the ledger row,
     * so a screen cannot show one question and record the other.
     *
     * `outstanding()` filters on the notice mode of a version CHANGE, which is meaningless for a
     * subject who never accepted anything: a document first published as a silent editorial
     * version was never in that set, so a form mounted with `FirstUseGate` rendered zero
     * documents while `statusFor()` reported the same keys as owed. Measured on 0.19.0, and the
     * reason this seam exists (LegalConsent's own `ConsentMethod::FirstUseGate` docblock had
     * described the case since it was introduced).
     *
     * `$method` is `#[Locked]`, so the choice is the embedding application's and not the browser's.
     *
     * A gate-question mount asks both and returns the union — the same set `EnsureLegalConsent`
     * counts when `gate.first_use` is on. It is not a convenience: the branch a host would
     * otherwise write inverts what the middleware computed two lines earlier, so every one with
     * OAuth, magic links or invitations wrote the identical one, and a subject who owed BOTH a
     * first acceptance and a re-consent still saw two screens in sequence with nothing explaining
     * why the second looked like the first.
     *
     * The union carries its own provenance. `$questionByKey` records which of the two sets each
     * key came from, because the answer lands in an append-only ledger row: a first acceptance
     * filed as `re_consent_gate` asserts a change that never happened, which is the failure
     * `ConsentMethod::FirstUseGate` exists to prevent and describes in its own docblock. A mount
     * that names a method keeps writing that method for every key, unchanged.
     *
     * Ordering puts `outstanding()` first and appends only the first-acceptance keys it does not
     * already hold. A key can legitimately be in both — never accepted AND materially changed
     * since publication — and the change question is the stricter of the two, so it wins.
     *
     * @return Collection<int, LegalDocument>
     */
    private function pendingFor(Model $subject): Collection
    {
        $manager = app(ConsentManager::class);

        if (! $this->answersGateQuestion) {
            $pending = $this->method === ConsentMethod::FirstUseGate
                ? $manager->firstAcceptance($subject, $this->locale)
                : $manager->outstanding($subject, $this->locale);

            $this->questionByKey = $pending
                ->mapWithKeys(fn (LegalDocument $document): array => [$document->key => $this->method])
                ->all();

            return $pending;
        }

        $changed = $manager->outstanding($subject, $this->locale);
        $changedKeys = $changed->pluck('key')->all();

        $first = $manager->firstAcceptance($subject, $this->locale)
            ->reject(fn (LegalDocument $document): bool => in_array($document->key, $changedKeys, true))
            ->values();

        $this->questionByKey = array_merge(
            $changed->mapWithKeys(fn (LegalDocument $d): array => [$d->key => ConsentMethod::ReConsentGate])->all(),
            $first->mapWithKeys(fn (LegalDocument $d): array => [$d->key => ConsentMethod::FirstUseGate])->all(),
        );

        return $changed->concat($first)->values();
    }

    /**
     * The provenance to write for one key.
     *
     * A named mount answers with itself. Under a null mount the answer comes from the set the key
     * was rendered from; a key that is in neither set is not on this screen, and `ReConsentGate`
     * is the honest fallback there because the only actions that can reach it — objection and
     * termination — act on a holding the subject already has, which is by definition not a first
     * acceptance.
     */
    private function questionFor(string $key): ConsentMethod
    {
        if (! $this->answersGateQuestion) {
            return $this->method;
        }

        return $this->questionByKey[$key] ?? ConsentMethod::ReConsentGate;
    }

    public function render(): View
    {
        $subject = $this->subject();

        $pending = $subject instanceof Model ? $this->pendingFor($subject) : new Collection;

        // Capture the content hash of each shown document into component state (persisted across the
        // Livewire request), so submit() can pass what the subject ACTUALLY saw — not a value
        // re-fetched live, which would make the accept-time TOCTOU guard vacuous.
        $this->hashes = [];

        foreach ($pending as $document) {
            // The same fingerprint accept() compares against — body hash folded with the acceptance
            // sentence, because that sentence is the only document text this form actually shows.
            $this->hashes[$document->key] = DefaultConsentManager::acceptanceFingerprint($document);
        }

        // The gate is the sharpest of the three surfaces that show a document: the subject cannot
        // continue until they agree, and until 0.13 the view rendered the acceptance sentence as
        // plain label text with no way to open the text behind it. A forced agreement without access
        // to the wording is what Art. 7(2) and recital 42 are about.
        return view('legal-consent::livewire.reconsent-form', [
            'pending' => $pending,
            'urls' => app(DocumentUrlResolver::class)->keyedBy($pending),
        ]);
    }

    private function subject(): ?Model
    {
        $user = auth()->user();

        return $user instanceof Model ? $user : null;
    }
}
