{{-- POLLS ONLY WHILE A QUEUED TRANSLATION IS RUNNING, and only where one can run at all: the
     component answers false when `translation.queue` is off, so an application without a queue never
     polls. A dispatched job is invisible to the page that dispatched it, and without this the
     operator would click Translate, read that it is running, and have to guess when to reload.

     ⚠️ `?? false` because this view is ALSO rendered standalone, by the tests that check its markup
     against real components. There is no Livewire component behind those, so the flag is absent —
     and absent means "do not poll", which is the only thing a render without a component could
     honestly mean. Calling `$this->translating()` here instead threw on every one of them. --}}
<div @if ($translating ?? false) wire:poll.3s @endif>
    {{-- Left out where the embedding page titles itself (`:heading="false"`), the same switch the
         consent panel carries. The landmark then takes its name DIRECTLY rather than pointing at a
         heading that is no longer in the document: `aria-labelledby` at a missing id names nothing,
         and an unnamed region is not an improvement on a duplicated title.
         `?? true` for a render outside the component, which passes no such flag. --}}
    <section @if ($heading ?? true) aria-labelledby="legal-text-editor-heading" @else aria-label="{{ ($documentName ?? $key).' — '.($languageName ?? $locale) }}" @endif>
        @if ($heading ?? true)
            <h1 id="legal-text-editor-heading">{{ ($documentName ?? $key) }} — {{ ($languageName ?? $locale) }}</h1>
        @endif

        {{-- Whether a human has signed off on these exact bytes, on the screen where that happens.
             It reached this view and was rendered nowhere, so a reviewed draft looked exactly like
             an unreviewed one as soon as the status line was replaced by the next action. Labeled
             rather than shown as a bare word — see the WireKit twin. --}}
        @if ($reviewState !== null)
            <p>{{ __('legal-consent::ui.admin_review_state') }}
                <strong>{{ __(\Pushery\LegalConsent\Enums\ReviewState::from($reviewState)->label()) }}</strong></p>
        @endif

        {{-- WCAG 4.1.3: the result of Save / Translate / Mark reviewed is announced here. Always in
             the DOM so a live region added together with its text still announces. --}}
        <p role="status" aria-live="polite" wire:key="legal-text-editor-status"><span wire:key="legal-text-editor-status-{{ $statusNonce }}">{{ $status }}</span></p>

        {{-- WCAG 4.1.3: the stale-source warning stays always-present and only its inner text is gated,
             so a staleness that flips true as the RESULT of a Livewire action is still announced (an
             @if that inserts the whole role="alert" with its text would not be). --}}
        <p role="alert" aria-live="assertive" wire:key="legal-text-editor-stale">@if ($staleNotice ?? null){{ __($staleNotice) }}@endif</p>

        {{-- Plain-stub editor: a textarea bound straight to the property. The WireKit variant swaps
             in <x-wirekit::editor> with the same wire:model (see the published stub). This stub ships
             no CSS — when you skin it, give text inputs font-size >= 16px (iOS zooms on focus below
             that) and interactive controls a >= 24px hit target (WCAG 2.5.8). --}}
        <label for="legal-text-body">{{ __('legal-consent::ui.admin_body_label') }}</label>
        <textarea id="legal-text-body" wire:model="body" rows="20"></textarea>

        <div>
            <button type="button" wire:click="save" wire:loading.attr="aria-busy" wire:target="save">{{ __('legal-consent::ui.admin_save') }}</button>

            @unless ($isSource)
                <button type="button" wire:click="translate" wire:loading.attr="aria-busy" wire:target="translate">{{ __('legal-consent::ui.admin_translate', ['locale' => $sourceLanguage]) }}</button>
            @endunless

            {{-- Only while there is something to stamp — see the WireKit twin for why. Both views
                 carry the same capability: a consumer on the plain stub is reading the same sign-off
                 screen. --}}
            @if ($reviewState !== 'reviewed')
                <button type="button" wire:click="markReviewed" wire:loading.attr="aria-busy" wire:target="markReviewed">{{ __('legal-consent::ui.admin_mark_reviewed') }}</button>
            @endif

            {{-- Only where it can do something — a translation that has a draft. See the WireKit
                 twin for why the source is excluded.

                 ⚠️ NO CONFIRMATION HERE, and that is stated rather than left to be discovered. This
                 stub names no framework and needs no JavaScript, so it has no dialog to put one
                 behind, and `confirm()` renders outside any design system and is not something a
                 package should inject into a consumer's page. A host that wants the step wraps this
                 control; the WireKit twin ships it. --}}
            @if (! $isSource && $reviewState !== null)
                <button type="button" wire:click="discard" wire:loading.attr="aria-busy" wire:target="discard">{{ __('legal-consent::ui.admin_discard') }}</button>
            @endif
        </div>

        {{-- RELEASE WITH AN OBJECTION WINDOW — a deemed-consent change (§ 308 Nr. 5 BGB).
             It lives HERE and not on the manager grid, and that is the manager's own stated
             position: a release that binds people by their SILENCE is a per-change legal call, not
             a one-click action on an overview. Until now that sentence named two homes for it, the
             editor and the CLI, and only the CLI had it — an application with an admin UI had no
             in-app path to a capability this package implements end to end.

             Plain form controls with no JavaScript: `type="date"` is what a browser already gives
             a keyboard and a screen reader for free, and this stub ships no CSS or JS by design. --}}
        <h2>{{ __('legal-consent::ui.admin_deemed_heading') }}</h2>
        <p>{{ __('legal-consent::ui.admin_deemed_explainer') }}</p>

        <div>
            <label for="legal-deemed-announce">{{ __('legal-consent::ui.admin_deemed_announce') }}</label>
            <input id="legal-deemed-announce" type="date" wire:model="announceAt">

            <label for="legal-deemed-deadline">{{ __('legal-consent::ui.admin_deemed_deadline') }}</label>
            <input id="legal-deemed-deadline" type="date" wire:model="objectionDeadline">

            <label for="legal-deemed-enforce">{{ __('legal-consent::ui.admin_deemed_enforce') }}</label>
            <input id="legal-deemed-enforce" type="date" wire:model="enforceAt">

            {{-- THE CLASSIFICATION, and it sits with the dates rather than apart from them because
                 one release carries both. The five controls around it schedule the change; these
                 two say what KIND of change it is, which is what a deemed release is later argued
                 from. A `<select>` for the regime because the publisher refuses one it does not
                 know, and free text for the class because the publisher takes any and the command
                 line already calls it a tag. --}}
            <label for="legal-deemed-regime">{{ __('legal-consent::ui.admin_deemed_regime') }}</label>
            <select id="legal-deemed-regime" wire:model="regime">
                <option value="">{{ __('legal-consent::ui.admin_deemed_regime_none') }}</option>
                {{-- THE PUBLISHER'S OWN LIST, read from it rather than written out here: it
                     refuses a regime it does not know, so a second vocabulary on this screen would
                     be free to drift -- and the drift shows up as a refusal on a release somebody
                     has already scheduled.

                     ⚠️ READ AS THE CONSTANT, NOT THROUGH A COMPONENT METHOD. `$this` is bound only
                     while Livewire renders this view; the package's own view tests render it
                     directly, and `$this->regimes()` died there with "Using $this when not in
                     object context" -- a published stub has to render wherever a consumer puts it.
                     Measured on the integration gate, not guessed. --}}
                @foreach (\Pushery\LegalConsent\Support\LegalDocumentPublisher::REGIMES as $available)
                    <option value="{{ $available }}">{{ $available }}</option>
                @endforeach
            </select>

            <label for="legal-deemed-change-class">{{ __('legal-consent::ui.admin_deemed_change_class') }}</label>
            <input id="legal-deemed-change-class" type="text" wire:model="changeClass" aria-describedby="legal-deemed-change-class-hint">
            <p id="legal-deemed-change-class-hint">{{ __('legal-consent::ui.admin_deemed_change_class_hint') }}</p>

            <label for="legal-deemed-termination">
                <input id="legal-deemed-termination" type="checkbox" wire:model="offersTermination">
                {{ __('legal-consent::ui.admin_deemed_offers_termination') }}
            </label>

            <label for="legal-deemed-keeps">
                <input id="legal-deemed-keeps" type="checkbox" wire:model="keepsUnmodified">
                {{ __('legal-consent::ui.admin_deemed_keeps_unmodified') }}
            </label>

            <button type="button" wire:click="releaseDeemed" wire:loading.attr="aria-busy" wire:target="releaseDeemed">{{ __('legal-consent::ui.admin_deemed_submit') }}</button>
        </div>

        <h2>{{ __('legal-consent::ui.admin_preview') }}</h2>
        {{-- The preview renders the already-sanitized stored body — the exact bytes a publish freezes,
             so what you see here is what the subject will see and the ledger will prove. --}}
        {{-- ⚠️ `role="region"`, BECAUSE A BARE `<div>` CANNOT BE NAMED. A div with no role maps to
             `generic`, and ARIA forbids naming a generic element — so browsers and assistive
             technology DISCARD the label, and `admin_preview_label` reached nobody in any of the
             seven locales it is translated into. The preview is a block of rendered legal HTML
             with headings of its own; without a landmark there is nothing to say where it starts. --}}
        <div role="region" aria-label="{{ __('legal-consent::ui.admin_preview_label') }}">{!! $preview !!}</div>
    </section>
</div>
