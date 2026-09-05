<div>
    <section aria-labelledby="legal-text-editor-heading">
        <h1 id="legal-text-editor-heading">{{ $key }} — {{ $locale }}</h1>

        {{-- WCAG 4.1.3: the result of Save / Translate / Mark reviewed is announced here. Always in
             the DOM so a live region added together with its text still announces. --}}
        <p role="status" aria-live="polite" wire:key="legal-text-editor-status"><span wire:key="legal-text-editor-status-{{ $statusNonce }}">{{ $status }}</span></p>

        {{-- WCAG 4.1.3: the stale-source warning stays always-present and only its inner text is gated,
             so a staleness that flips true as the RESULT of a Livewire action is still announced (an
             @if that inserts the whole role="alert" with its text would not be). --}}
        <p role="alert" aria-live="assertive" wire:key="legal-text-editor-stale">@if ($stale){{ __('legal-consent::ui.admin_stale') }}@endif</p>

        {{-- Plain-stub editor: a textarea bound straight to the property. The WireKit variant swaps
             in <x-wirekit::editor> with the same wire:model (see the published stub). This stub ships
             no CSS — when you skin it, give text inputs font-size >= 16px (iOS zooms on focus below
             that) and interactive controls a >= 24px hit target (WCAG 2.5.8). --}}
        <label for="legal-text-body">{{ __('legal-consent::ui.admin_body_label') }}</label>
        <textarea id="legal-text-body" wire:model="body" rows="20"></textarea>

        <div>
            <button type="button" wire:click="save">{{ __('legal-consent::ui.admin_save') }}</button>

            @unless ($isSource)
                <button type="button" wire:click="translate">{{ __('legal-consent::ui.admin_translate', ['locale' => $sourceLocale]) }}</button>
            @endunless

            <button type="button" wire:click="markReviewed">{{ __('legal-consent::ui.admin_mark_reviewed') }}</button>
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

            <label for="legal-deemed-termination">
                <input id="legal-deemed-termination" type="checkbox" wire:model="offersTermination">
                {{ __('legal-consent::ui.admin_deemed_offers_termination') }}
            </label>

            <label for="legal-deemed-keeps">
                <input id="legal-deemed-keeps" type="checkbox" wire:model="keepsUnmodified">
                {{ __('legal-consent::ui.admin_deemed_keeps_unmodified') }}
            </label>

            <button type="button" wire:click="releaseDeemed">{{ __('legal-consent::ui.admin_deemed_submit') }}</button>
        </div>

        <h2>{{ __('legal-consent::ui.admin_preview') }}</h2>
        {{-- The preview renders the already-sanitized stored body — the exact bytes a publish freezes,
             so what you see here is what the subject will see and the ledger will prove. --}}
        <div aria-label="{{ __('legal-consent::ui.admin_preview_label') }}">{!! $preview !!}</div>
    </section>
</div>
