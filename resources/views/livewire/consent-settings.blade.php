{{--
    View for the ConsentSettings Livewire component. Single root element (Livewire
    requirement). The three legally distinct blocks stay separate; only a real consent
    (withdrawable) shows a one-click withdraw control (Art. 7(3)). Style freely.
--}}
<div class="legal-consent-settings">
    <h2>{{ __('legal-consent::ui.settings_heading') }}</h2>

    {{-- WCAG 4.1.3 + 2.4.3: after a withdrawal the row's button is gone, so the result is announced
         here (a polite live region, always present so the update is spoken) and focus moves to it so
         it does not drop to <body>. Focus is driven by x-effect, not x-init: x-init runs once on an
         always-present element and does NOT re-run when Livewire morphs it, so the focus never moved.
         x-effect re-runs whenever $wire.status changes, i.e. exactly when a withdrawal sets it. --}}
    <p role="status" aria-live="polite" tabindex="-1" wire:key="lc-settings-status"
        x-effect="($wire.status ?? '') !== '' && $el.focus()">{{ $status ?? '' }}</p>

    <section aria-labelledby="lc-contracts">
        <h3 id="lc-contracts">{{ __('legal-consent::ui.contracts_heading') }}</h3>
        <ul>
            @foreach ($contracts as $item)
                <li>{{ $item['title'] }} (v{{ $item['version'] }})</li>
            @endforeach
        </ul>
    </section>

    <section aria-labelledby="lc-acknowledgements">
        <h3 id="lc-acknowledgements">{{ __('legal-consent::ui.acknowledgements_heading') }}</h3>
        <ul>
            @foreach ($acknowledgements as $item)
                <li>{{ $item['title'] }} (v{{ $item['version'] }})</li>
            @endforeach
        </ul>
    </section>

    <section aria-labelledby="lc-consents">
        <h3 id="lc-consents">{{ __('legal-consent::ui.consents_heading') }}</h3>
        <ul>
            @foreach ($consents as $item)
                <li>
                    <span>{{ $item['title'] }}</span>
                    @if ($item['held'] && $item['withdrawable'])
                        <button type="button" aria-label="{{ __('legal-consent::ui.withdraw_for', ['title' => $item['title']]) }}" wire:click="withdraw(@js($item['key']))">
                            {{ __('legal-consent::ui.withdraw') }}
                        </button>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
</div>
