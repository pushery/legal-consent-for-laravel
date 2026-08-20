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
        x-effect="$wire.statusNonce > 0 && $el.focus()"><span wire:key="lc-settings-status-{{ $statusNonce }}">{{ $status ?? '' }}</span></p>

    {{-- Each title links to the document when the host configured `legal-consent.document_url`,
         and renders as plain text otherwise. Deciding to withdraw a consent without being able to
         re-read what was consented to is the one thing this screen must not ask of anyone
         (Art. 7(3): as easy to withdraw as to give). --}}
    <section aria-labelledby="lc-contracts">
        <h3 id="lc-contracts">{{ __('legal-consent::ui.contracts_heading') }}</h3>
        <ul>
            @foreach ($contracts as $item)
                <li>
                    @if (($item['url'] ?? null) !== null)
                        <a href="{{ $item['url'] }}" target="_blank" rel="noopener noreferrer">{{ $item['title'] }}</a>
                    @else
                        {{ $item['title'] }}
                    @endif
                    (v{{ $item['version'] }})
                </li>
            @endforeach
        </ul>
    </section>

    <section aria-labelledby="lc-acknowledgements">
        <h3 id="lc-acknowledgements">{{ __('legal-consent::ui.acknowledgements_heading') }}</h3>
        <ul>
            @foreach ($acknowledgements as $item)
                <li>
                    @if (($item['url'] ?? null) !== null)
                        <a href="{{ $item['url'] }}" target="_blank" rel="noopener noreferrer">{{ $item['title'] }}</a>
                    @else
                        {{ $item['title'] }}
                    @endif
                    (v{{ $item['version'] }})
                </li>
            @endforeach
        </ul>
    </section>

    <section aria-labelledby="lc-consents">
        <h3 id="lc-consents">{{ __('legal-consent::ui.consents_heading') }}</h3>
        <ul>
            @foreach ($consents as $item)
                <li>
                    @if (($item['url'] ?? null) !== null)
                        <a href="{{ $item['url'] }}" target="_blank" rel="noopener noreferrer">{{ $item['title'] }}</a>
                    @else
                        <span>{{ $item['title'] }}</span>
                    @endif
                    @if ($item['held'] && $item['withdrawable'])
                        <button type="button" aria-label="{{ __('legal-consent::ui.withdraw_for', ['title' => $item['title']]) }}" wire:click="withdraw(@js($item['key']))">
                            {{ __('legal-consent::ui.withdraw') }}
                        </button>
                    {{-- The counterpart, and only where the embedding screen asked for it. A
                         per-item label again, for the same reason the withdraw button has one:
                         a screen reader's button list of five identical "Give" entries names
                         nothing. --}}
                    @elseif (! $item['held'] && $this->allowGrant)
                        <button type="button" aria-label="{{ __('legal-consent::ui.grant_for', ['title' => $item['title']]) }}" wire:click="grant(@js($item['key']))">
                            {{ __('legal-consent::ui.grant') }}
                        </button>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
</div>
