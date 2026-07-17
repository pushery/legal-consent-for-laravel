{{--
    WireKit-native view for the LegalTextManager Livewire component. Publish with
    `--tag=legal-consent-wirekit`; it overrides `legal-consent::livewire.legal-text-manager`.

    Single root element (Livewire requires it). Needs `pushery/wirekit` in the host app and
    `@wirekitScripts` in the layout (Release all locales confirms via an alert-dialog).
--}}
<div>
    <x-wirekit::stack gap="lg" as="section" aria-labelledby="lc-legal-texts">
        <x-wirekit::heading :level="1" id="lc-legal-texts">{{ __('legal-consent::ui.admin_heading') }}</x-wirekit::heading>

        {{-- WCAG 4.1.3: the release result (or the reason it did not happen) is announced here. The
             region is ALWAYS in the DOM so the update is spoken — a live region inserted together
             with its text is not announced. The text is a plain x-wirekit::text, NOT an
             x-wirekit::alert: the alert is itself a role="status" region, and nesting a live region
             in this one would double-announce. --}}
        <div role="status" aria-live="polite" wire:key="lc-status">
            @if ($status !== '')
                <x-wirekit::text>{{ $status }}</x-wirekit::text>
            @endif
        </div>

        <x-wirekit::alert variant="neutral">{{ __('legal-consent::ui.admin_policy') }}</x-wirekit::alert>

        {{-- tableLabel names the always-focusable responsive scroll region (else it falls back to a
             generic "Scrollable table"). NOTE: the document cell below is a plain table.td, not a
             row header — WireKit's table.th hardcodes scope="col", so a per-row header is not
             reachable through the component (upstream gap); the release buttons carry a per-document
             accessible name instead, so a screen-reader user can still tell the rows apart. --}}
        <x-wirekit::table :tableLabel="__('legal-consent::ui.admin_heading')">
            <x-wirekit::table.head>
                <x-wirekit::table.row>
                    <x-wirekit::table.th>{{ __('legal-consent::ui.admin_document') }}</x-wirekit::table.th>
                    @foreach ($locales as $locale)
                        <x-wirekit::table.th>{{ $locale }}</x-wirekit::table.th>
                    @endforeach
                    <x-wirekit::table.th>{{ __('legal-consent::ui.admin_release') }}</x-wirekit::table.th>
                </x-wirekit::table.row>
            </x-wirekit::table.head>
            <x-wirekit::table.body>
                @foreach ($keys as $key)
                    <x-wirekit::table.row wire:key="row-{{ $key }}">
                        <x-wirekit::table.td>{{ $key }}</x-wirekit::table.td>
                        @foreach ($locales as $locale)
                            @php($cell = $rows[$key][$locale])
                            <x-wirekit::table.td>
                                @if (! $cell['written'])
                                    <x-wirekit::badge intent="neutral" size="sm">{{ __('legal-consent::ui.admin_not_written') }}</x-wirekit::badge>
                                @else
                                    <x-wirekit::badge :intent="$cell['publishable'] ? 'success' : 'warning'" size="sm">{{ $cell['review_state'] }}</x-wirekit::badge>
                                    @if ($cell['machine'])
                                        <x-wirekit::badge intent="info" size="sm">{{ __('legal-consent::ui.admin_machine') }}</x-wirekit::badge>
                                    @endif
                                    @if ($cell['stale'])
                                        <x-wirekit::badge intent="warning" size="sm">{{ __('legal-consent::ui.admin_needs_update') }}</x-wirekit::badge>
                                    @elseif ($cell['unpublished_changes'])
                                        <x-wirekit::badge intent="neutral" size="sm">{{ __('legal-consent::ui.admin_unpublished') }}</x-wirekit::badge>
                                    @endif
                                @endif
                            </x-wirekit::table.td>
                        @endforeach
                        <x-wirekit::table.td>
                            @if ($rows[$key]['_release']['ready'])
                                <x-wirekit::alert-dialog>
                                    <x-slot:trigger>
                                        <x-wirekit::button size="sm" :aria-label="__('legal-consent::ui.admin_release_all').' — '.$key">{{ __('legal-consent::ui.admin_release_all') }}</x-wirekit::button>
                                    </x-slot:trigger>
                                    <x-slot:title>{{ __('legal-consent::ui.admin_release_confirm_title') }}</x-slot:title>
                                    <x-slot:description>{{ __('legal-consent::ui.admin_release_confirm_body') }}</x-slot:description>
                                    <x-slot:confirm>
                                        <x-wirekit::button wire:click="releaseAll('{{ $key }}')">{{ __('legal-consent::ui.admin_release_all') }}</x-wirekit::button>
                                    </x-slot:confirm>
                                </x-wirekit::alert-dialog>
                            @else
                                <x-wirekit::button size="sm" disabled aria-describedby="blocking-{{ $key }}" :aria-label="__('legal-consent::ui.admin_release_all').' — '.$key">{{ __('legal-consent::ui.admin_release_all') }}</x-wirekit::button>
                                <x-wirekit::stack gap="xs" id="blocking-{{ $key }}">
                                    @foreach ($rows[$key]['_release']['blocking'] as $locale => $reason)
                                        <x-wirekit::text size="sm">{{ $locale }}: {{ $reason }}</x-wirekit::text>
                                    @endforeach
                                </x-wirekit::stack>
                            @endif
                        </x-wirekit::table.td>
                    </x-wirekit::table.row>
                @endforeach
            </x-wirekit::table.body>
        </x-wirekit::table>
    </x-wirekit::stack>
</div>
