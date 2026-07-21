<div>
    <section aria-labelledby="legal-text-editor-heading">
        <h1 id="legal-text-editor-heading">{{ $key }} — {{ $locale }}</h1>

        {{-- WCAG 4.1.3: the result of Save / Translate / Mark reviewed is announced here. Always in
             the DOM so a live region added together with its text still announces. --}}
        <p role="status" aria-live="polite" wire:key="legal-text-editor-status">{{ $status }}</p>

        {{-- WCAG 4.1.3: the stale-source warning stays always-present and only its inner text is gated,
             so a staleness that flips true as the RESULT of a Livewire action is still announced (an
             @if that inserts the whole role="alert" with its text would not be). --}}
        <p role="alert" aria-live="assertive" wire:key="legal-text-editor-stale">@if ($stale){{ __('legal-consent::ui.admin_stale') }}@endif</p>

        {{-- Plain-stub editor: a textarea bound straight to the property. The WireKit variant swaps
             in <x-wirekit::editor> and binds via $wire.set (see the published stub). This stub ships
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

        <h2>{{ __('legal-consent::ui.admin_preview') }}</h2>
        {{-- The preview renders the already-sanitized stored body — the exact bytes a publish freezes,
             so what you see here is what the subject will see and the ledger will prove. --}}
        <div aria-label="{{ __('legal-consent::ui.admin_preview_label') }}">{!! $preview !!}</div>
    </section>
</div>
