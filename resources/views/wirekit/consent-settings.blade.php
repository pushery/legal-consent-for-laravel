{{--
    WireKit-native variant of the "My consents" settings screen. Publish with
    `--tag=legal-consent-wirekit`. Built from real `x-wirekit::*` components; needs
    `pushery/wirekit` in the host app and `@wirekitScripts` in the layout (the withdraw
    confirmation is an alert-dialog).

    The three blocks stay legally separate and must not be merged into one list: a contract is
    agreed, a privacy notice is only acknowledged ("zur Kenntnis genommen", never "ich willige
    ein"), and only a real consent is withdrawable (Art. 7(3)). Collapsing them would blur exactly
    the distinction this package exists to keep.

    $contracts / $acknowledgements / $consents: list{key, title, version, held, withdrawable}.
--}}
<x-wirekit::stack gap="lg" class="legal-consent-settings">
    <x-wirekit::heading :level="2">{{ __('legal-consent::ui.settings_heading') }}</x-wirekit::heading>

    <x-wirekit::stack gap="sm" as="section" aria-labelledby="lc-contracts">
        <x-wirekit::heading :level="3" id="lc-contracts">{{ __('legal-consent::ui.contracts_heading') }}</x-wirekit::heading>
        <x-wirekit::stack gap="xs">
            @foreach ($contracts as $item)
                <x-wirekit::text>
                    {{ $item['title'] }} <x-wirekit::badge intent="neutral" size="sm">v{{ $item['version'] }}</x-wirekit::badge>
                </x-wirekit::text>
            @endforeach
        </x-wirekit::stack>
    </x-wirekit::stack>

    <x-wirekit::stack gap="sm" as="section" aria-labelledby="lc-acknowledgements">
        <x-wirekit::heading :level="3" id="lc-acknowledgements">{{ __('legal-consent::ui.acknowledgements_heading') }}</x-wirekit::heading>
        <x-wirekit::stack gap="xs">
            @foreach ($acknowledgements as $item)
                <x-wirekit::text>
                    {{ $item['title'] }} <x-wirekit::badge intent="neutral" size="sm">v{{ $item['version'] }}</x-wirekit::badge>
                </x-wirekit::text>
            @endforeach
        </x-wirekit::stack>
    </x-wirekit::stack>

    <x-wirekit::stack gap="sm" as="section" aria-labelledby="lc-consents">
        <x-wirekit::heading :level="3" id="lc-consents">{{ __('legal-consent::ui.consents_heading') }}</x-wirekit::heading>
        <x-wirekit::stack gap="xs">
            @foreach ($consents as $item)
                <x-wirekit::stack gap="sm" :wrap="true" class="legal-consent-settings__consent">
                    <x-wirekit::text>{{ $item['title'] }}</x-wirekit::text>

                    @if ($item['held'] && $item['withdrawable'])
                        {{-- A withdrawal is irreversible: it appends a Withdrawn row to an
                             append-only ledger and cannot be taken back. That earns a real
                             confirmation — an alert-dialog, never the native browser confirm, which
                             renders unstyled and outside the design system. --}}
                        <x-wirekit::alert-dialog :name="'lc-withdraw-'.$item['key']">
                            <x-slot:trigger>
                                <x-wirekit::button intent="danger" surface="outline" :aria-label="__('legal-consent::ui.withdraw_for', ['title' => $item['title']])">
                                    {{ __('legal-consent::ui.withdraw') }}
                                </x-wirekit::button>
                            </x-slot:trigger>

                            <x-wirekit::alert-dialog.title>
                                {{ __('legal-consent::ui.withdraw_confirm_title') }}
                            </x-wirekit::alert-dialog.title>

                            <x-wirekit::alert-dialog.description>
                                {{ __('legal-consent::ui.withdraw_confirm_body', ['title' => $item['title']]) }}
                            </x-wirekit::alert-dialog.description>

                            <x-wirekit::alert-dialog.actions>
                                <x-wirekit::alert-dialog.cancel>
                                    {{ __('legal-consent::ui.cancel') }}
                                </x-wirekit::alert-dialog.cancel>

                                <x-wirekit::button intent="danger" wire:click="withdraw(@js($item['key']))">
                                    {{ __('legal-consent::ui.withdraw') }}
                                </x-wirekit::button>
                            </x-wirekit::alert-dialog.actions>
                        </x-wirekit::alert-dialog>
                    @endif
                </x-wirekit::stack>
            @endforeach
        </x-wirekit::stack>
    </x-wirekit::stack>
</x-wirekit::stack>
