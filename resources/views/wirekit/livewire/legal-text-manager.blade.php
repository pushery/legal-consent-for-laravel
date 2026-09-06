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
             in this one would double-announce.

             ⚠️ POLITE, MATCHING THE PLAIN TWIN — and this comment used to say the opposite, having
             raised it to `assertive` so the two would agree. The agreement was right and the
             direction was wrong: `role="alert"` implies assertive, and this same element also
             receives focus, so assistive technology says the message twice — once as a live-region
             interruption, once as the name of the newly focused element. Dropping the focus move
             instead would send a keyboard user to <body> after an irreversible action (WCAG 2.4.3).
             The focus move is already immediate, so politeness costs no urgency, and it is what the
             package's three other status regions have used all along.

             tabindex="-1" + x-effect is the focus half. The release is confirmed in a modal that
             closes itself; with nowhere to send focus it falls to <body> (WCAG 2.4.3), leaving a
             keyboard user at the top of the document after an irreversible action. wire:key cannot
             do it — the element is always present and gets morphed, so nothing re-runs. --}}
        <div role="status" aria-live="polite" tabindex="-1" wire:key="lc-status"
             x-effect="$wire.statusNonce > 0 && $el.focus()">
            @if ($status !== '')
                <div wire:key="lc-status-{{ $statusNonce }}"><x-wirekit::text>{{ $status }}</x-wirekit::text></div>
            @endif
        </div>

        <x-wirekit::alert intent="neutral">{{ __('legal-consent::ui.admin_policy') }}</x-wirekit::alert>

        {{-- tableLabel names the always-focusable responsive scroll region (else it falls back to a
             generic "Scrollable table"). The document cell below is a real row header
             (header-scope="row") — WCAG 1.3.1: it is what associates every status
             cell in the row with the document it describes, so a screen reader announces "terms" with
             the cell instead of leaving a bare "not written" adrift in a grid.

             The prop is `header-scope`, NOT `scope`: `scope` is WireKit's token-scope override, which
             every component shares for scoped theming. Passing scope="row" there would silently
             re-theme the cell and still emit scope="col". --}}
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
                        <x-wirekit::table.th header-scope="row">{{ $key }}</x-wirekit::table.th>
                        @foreach ($locales as $locale)
                            @php($cell = $rows[$key][$locale])
                            <x-wirekit::table.td>
                                @if (! $cell['written'])
                                    <x-wirekit::badge intent="neutral" size="sm">{{ __('legal-consent::ui.admin_not_written') }}</x-wirekit::badge>
                                @else
                                    <x-wirekit::badge :intent="$cell['publishable'] ? 'success' : 'warning'" size="sm">{{ __($cell['review_state_label']) }}</x-wirekit::badge>
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
                                {{-- The installed alert-dialog exposes a `trigger` slot
                                     plus a default-slot panel body built from alert-dialog.title /
                                     .description / .actions sub-components — NOT named title/description/
                                     confirm slots, which it silently drops (leaving an empty dialog with no
                                     confirm button, so the release is unreachable through the UI). --}}
                                <x-wirekit::alert-dialog :name="'lc-release-'.$key">
                                    <x-slot:trigger>
                                        <x-wirekit::button size="sm" :aria-label="__('legal-consent::ui.admin_release_all').' — '.$key">{{ __('legal-consent::ui.admin_release_all') }}</x-wirekit::button>
                                    </x-slot:trigger>

                                    <x-wirekit::alert-dialog.title>
                                        {{ __('legal-consent::ui.admin_release_confirm_title') }}
                                    </x-wirekit::alert-dialog.title>

                                    <x-wirekit::alert-dialog.description>
                                        {{ __('legal-consent::ui.admin_release_confirm_body') }}
                                    </x-wirekit::alert-dialog.description>

                                    <x-wirekit::alert-dialog.actions>
                                        <x-wirekit::alert-dialog.cancel>
                                            {{ __('legal-consent::ui.cancel') }}
                                        </x-wirekit::alert-dialog.cancel>

                                        {{-- x-on:click="close()" alongside wire:click: without it the
                                             dialog stays open behind aria-modal after confirming, so the
                                             status live region underneath is never announced and the
                                             backdrop keeps the page unreachable (WCAG 4.1.3). The parent
                                             alert-dialog provides close(); alert-dialog.cancel does the
                                             same for the cancel side. --}}
                                        {{-- `{{ Js::from() }}`, NOT `@js()`. A Blade directive inside
                                             a COMPONENT TAG attribute is never compiled: the tag
                                             compiler lifts the attribute out as a literal before the
                                             directive compiler sees it, so the text `@js($key)` is
                                             what reaches the browser — for every key, with no error
                                             anywhere, and the release simply never fires. An echo is
                                             compiled in that position and emits exactly what `@js()`
                                             emits on a plain element. The plain stub, whose button is
                                             a real `<button>`, uses the directive. --}}
                                        {{-- No busy state here, unlike every other action in these stubs:
                                             `x-on:click="close()"` tears the dialog down in the same click, so
                                             this button is out of the DOM before the response arrives. An
                                             `aria-busy` on it would flip on an element nobody can reach. The
                                             wait is reported by the status region the close reveals, which is
                                             what the close exists for. The plain twin's release button is not
                                             in a dialog, stays put, and does carry the pair. --}}
                                        <x-wirekit::button x-on:click="close()" wire:click="releaseAll({{ \Illuminate\Support\Js::from($key) }})">
                                            {{ __('legal-consent::ui.admin_release_all') }}
                                        </x-wirekit::button>
                                    </x-wirekit::alert-dialog.actions>
                                </x-wirekit::alert-dialog>
                            @else
                                {{-- The blocking reasons stay OUTSIDE aria-describedby, the same call
                                     the plain stub writes out: the button renders a native `disabled`
                                     (not `aria-disabled`) plus `disabled:pointer-events-none`, so it
                                     is not focusable and a description hung on it is never announced.
                                     They are the visible text below instead, reachable in read mode.
                                     The id below is therefore a styling hook only. It is deliberately
                                     NOT an aria target: pointing a describedby at it would restore the
                                     association this comment exists to prevent. --}}
                                <x-wirekit::button size="sm" disabled :aria-label="__('legal-consent::ui.admin_release_all').' — '.$key">{{ __('legal-consent::ui.admin_release_all') }}</x-wirekit::button>
                                <x-wirekit::stack gap="xs" id="blocking-{{ $key }}">
                                    @foreach ($rows[$key]['_release']['blocking'] as $locale => $reason)
                                        <x-wirekit::text size="sm">{{ $locale }}: {{ __($reason->label()) }}</x-wirekit::text>
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
