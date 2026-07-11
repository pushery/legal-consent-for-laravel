{{--
    WireKit-flavored variant of the "My consents" settings screen. Publish it with
    `--tag=legal-consent-wirekit` to override the plain stub, then adapt the elements to
    your WireKit components (buttons, cards, headings). It uses WireKit spacing TOKENS
    (never raw Tailwind) so it inherits your theme; a fully WireKit-native companion lives in
    the separate pushery/legal-consent-wirekit package. All i18n + the legal separation of the
    three blocks are preserved.

    $contracts / $acknowledgements / $consents: ['key','title','version','held','withdrawable'].
--}}
<div class="wk-stack wk-gap-lg legal-consent-settings">
    <h2 class="wk-heading">{{ __('legal-consent::ui.settings_heading') }}</h2>

    <section class="wk-stack wk-gap-sm" aria-labelledby="lc-contracts">
        <h3 class="wk-heading wk-heading-sm" id="lc-contracts">{{ __('legal-consent::ui.contracts_heading') }}</h3>
        <ul class="wk-list">
            @foreach ($contracts as $item)
                <li class="wk-list-item">{{ $item['title'] }} (v{{ $item['version'] }})</li>
            @endforeach
        </ul>
    </section>

    <section class="wk-stack wk-gap-sm" aria-labelledby="lc-acknowledgements">
        <h3 class="wk-heading wk-heading-sm" id="lc-acknowledgements">{{ __('legal-consent::ui.acknowledgements_heading') }}</h3>
        <ul class="wk-list">
            @foreach ($acknowledgements as $item)
                <li class="wk-list-item">{{ $item['title'] }} (v{{ $item['version'] }})</li>
            @endforeach
        </ul>
    </section>

    <section class="wk-stack wk-gap-sm" aria-labelledby="lc-consents">
        <h3 class="wk-heading wk-heading-sm" id="lc-consents">{{ __('legal-consent::ui.consents_heading') }}</h3>
        <ul class="wk-list">
            @foreach ($consents as $item)
                <li class="wk-list-item wk-row wk-justify-between wk-items-center">
                    <span>{{ $item['title'] }}</span>
                    @if ($item['held'] && $item['withdrawable'])
                        {{-- Swap for <wk:button variant="danger" wire:click=...> and a WireKit alert-dialog confirm. --}}
                        <button type="button" class="wk-button wk-button-danger" wire:click="withdraw(@js($item['key']))">
                            {{ __('legal-consent::ui.withdraw') }}
                        </button>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
</div>
