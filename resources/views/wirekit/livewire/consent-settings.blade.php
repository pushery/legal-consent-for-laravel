{{--
    WireKit-native view for the ConsentSettings Livewire component. Publish with
    `--tag=legal-consent-wirekit`; it overrides `legal-consent::livewire.consent-settings`.

    Single root element (Livewire requires it). Needs `pushery/wirekit` in the host app and
    `@wirekitScripts` in the layout (the withdraw confirmation is an alert-dialog).

    The three blocks stay legally separate: a contract is agreed, a privacy notice is only
    acknowledged, and only a real consent is withdrawable (Art. 7(3)).
--}}
<div class="legal-consent-settings">
    <x-wirekit::stack gap="lg">
        {{-- Each title links to the document when the host configured `legal-consent.document_url`,
             and renders as plain text otherwise. Deciding to withdraw a consent without being able
             to re-read what was consented to is the one thing this screen must not ask of anyone
             (Art. 7(3): as easy to withdraw as to give). --}}
        <x-wirekit::heading :level="2">{{ __('legal-consent::ui.settings_heading') }}</x-wirekit::heading>

        {{-- WCAG 4.1.3 + 2.4.3: the withdrawal result is announced here — the region is ALWAYS in the
             DOM (a live region inserted together with its text is not announced) and role="status"
             carries the announcement, so focus lands on the live region itself, exactly as in the
             plain view. The text is a plain x-wirekit::text, NOT an x-wirekit::alert (the alert is
             itself a role="status" region — nesting would double-announce). Focus is driven by
             x-effect, not x-init: x-init runs once and does not re-run on a Livewire morph. --}}
        <div role="status" aria-live="polite" tabindex="-1" wire:key="lc-settings-status"
            x-effect="$wire.statusNonce > 0 && $el.focus()">
            @if (($status ?? '') !== '')
                <div wire:key="lc-settings-status-{{ $statusNonce }}"><x-wirekit::text>{{ $status }}</x-wirekit::text></div>
            @endif
        </div>

        <x-wirekit::stack gap="sm" as="section" aria-labelledby="lc-contracts">
            <x-wirekit::heading :level="3" id="lc-contracts">{{ __('legal-consent::ui.contracts_heading') }}</x-wirekit::heading>
            <x-wirekit::stack gap="xs">
                @foreach ($contracts as $item)
                    <x-wirekit::text>
                        @if (($item['url'] ?? null) !== null)
                            <x-wirekit::link :href="$item['url']" external>{{ $item['title'] }}</x-wirekit::link>
                        @else
                            {{ $item['title'] }}
                        @endif
                        <x-wirekit::badge intent="neutral" size="sm">v{{ $item['version'] }}</x-wirekit::badge>
                    </x-wirekit::text>
                @endforeach
            </x-wirekit::stack>
        </x-wirekit::stack>

        <x-wirekit::stack gap="sm" as="section" aria-labelledby="lc-acknowledgements">
            <x-wirekit::heading :level="3" id="lc-acknowledgements">{{ __('legal-consent::ui.acknowledgements_heading') }}</x-wirekit::heading>
            <x-wirekit::stack gap="xs">
                @foreach ($acknowledgements as $item)
                    <x-wirekit::text>
                        @if (($item['url'] ?? null) !== null)
                            <x-wirekit::link :href="$item['url']" external>{{ $item['title'] }}</x-wirekit::link>
                        @else
                            {{ $item['title'] }}
                        @endif
                        <x-wirekit::badge intent="neutral" size="sm">v{{ $item['version'] }}</x-wirekit::badge>
                    </x-wirekit::text>
                @endforeach
            </x-wirekit::stack>
        </x-wirekit::stack>

        <x-wirekit::stack gap="sm" as="section" aria-labelledby="lc-consents">
            <x-wirekit::heading :level="3" id="lc-consents">{{ __('legal-consent::ui.consents_heading') }}</x-wirekit::heading>
            <x-wirekit::stack gap="xs">
                @foreach ($consents as $item)
                    <x-wirekit::stack gap="sm" :wrap="true" class="legal-consent-settings__consent">
                        <x-wirekit::text>
                            @if (($item['url'] ?? null) !== null)
                                <x-wirekit::link :href="$item['url']" external>{{ $item['title'] }}</x-wirekit::link>
                            @else
                                {{ $item['title'] }}
                            @endif
                        </x-wirekit::text>

                        @if ($item['held'] && $item['withdrawable'])
                            {{-- A withdrawal is irreversible: it appends a Withdrawn row to an
                                 append-only ledger. That earns a real confirmation — an
                                 alert-dialog, never the unstyled native browser confirm. --}}
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
</div>
