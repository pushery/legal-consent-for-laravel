{{--
    WireKit-native view for the LegalTextManager Livewire component. Publish with
    `--tag=legal-consent-wirekit`; it overrides `legal-consent::livewire.legal-text-manager`.

    Single root element (Livewire requires it). Needs `pushery/wirekit` in the host app and
    `@wirekitScripts` in the layout (Release all locales confirms via an alert-dialog).
--}}
<div>
    {{-- The label is assembled into an attribute bag, because a Blade `@if` INSIDE a component
         tag does not compile — it is emitted as literal text into the rendered attribute list.
         The landmark takes its name directly when the heading is gone: `aria-labelledby` pointing
         at a missing id names nothing, and an unnamed region is not an improvement on a duplicated
         title. `?? true` for a render outside the component, which passes no such flag. --}}
    @php($lcSection = new Illuminate\View\ComponentAttributeBag(($heading ?? true)
        ? ['aria-labelledby' => 'lc-legal-texts']
        : ['aria-label' => (string) __('legal-consent::ui.admin_heading')]))
    <x-wirekit::stack gap="lg" as="section" :attributes="$lcSection">
        @if ($heading ?? true)
            <x-wirekit::heading :level="1" id="lc-legal-texts">{{ __('legal-consent::ui.admin_heading') }}</x-wirekit::heading>
        @endif

        {{-- WCAG 4.1.3: the release result (or the reason it did not happen) is announced here. The
             region is ALWAYS in the DOM so the update is spoken — a live region inserted together
             with its text is not announced. The text is a plain x-wirekit::text, NOT an
             x-wirekit::alert: the alert is itself a role="status" region, and nesting a live region
             in this one would double-announce.

             Polite, matching the plain twin — and this comment used to say the opposite, having
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
        {{-- The legend, and it is what makes the short cells legible rather than cryptic.

             This matrix carries one column PER LOCALE, so the widest state word decides the table's
             width -- and the words are sentences: "Unveröffentlichte Änderungen" is 28 characters,
             and seven of those columns do not fit any desktop. Measured in a consuming application
             at 1728px: the table wanted 1407px of content space and had 1400. Letting the cells
             break inside a word makes it fit and stacks "Nicht geschrieben" one letter per line.

             So the cell carries the short form and the badge's accessible name carries the long one
             -- a screen reader announces the sentence, a sighted reader reads the legend once. Each
             short form is drawn from its own language's long wording rather than translated from
             English, because a legend only explains a word that belongs to the same family. --}}
        <x-wirekit::text size="sm" intent="muted" class="mb-[var(--space-wk-sm)]">
            @foreach ([
                'admin_not_written',
                'review_state_draft',
                'review_state_reviewed',
                'admin_machine',
                'admin_needs_update',
                'admin_unpublished',
            ] as $state)
                <span class="whitespace-nowrap">{{ __('legal-consent::ui.'.$state.'_short') }} = {{ __('legal-consent::ui.'.$state) }}</span>@if (! $loop->last) · @endif
            @endforeach
        </x-wirekit::text>

        {{-- The same two sentences as the plain twin, and for the same reason: the legend above
             maps a short form onto a long one, so it explains "Draft" with "Draft". What a first
             reader of this screen needs to hear is that REVIEWING PUBLISHES NOTHING, and then why
             the release waits on languages other than their own. A capability in one tree and not
             the other is exactly the drift a pair of shipped views invites. --}}
        @foreach (['review_state_reviewed', 'review_state_draft'] as $state)
            @if (__('legal-consent::ui.'.$state.'_description') !== '')
                <x-wirekit::text size="sm" intent="muted" class="mb-[var(--space-wk-sm)]">
                    {{ __('legal-consent::ui.'.$state) }}: {{ __('legal-consent::ui.'.$state.'_description') }}
                </x-wirekit::text>
            @endif
        @endforeach

        <x-wirekit::table :tableLabel="__('legal-consent::ui.admin_heading')">
            <x-wirekit::table.head>
                <x-wirekit::table.row>
                    <x-wirekit::table.th>{{ __('legal-consent::ui.admin_document') }}</x-wirekit::table.th>
                    @foreach ($locales as $locale)
                        <x-wirekit::table.th>{{ $locale }}</x-wirekit::table.th>
                    @endforeach
                </x-wirekit::table.row>
            </x-wirekit::table.head>
            <x-wirekit::table.body>
                @foreach ($keys as $key)
                    <x-wirekit::table.row wire:key="row-{{ $key }}">
                        <x-wirekit::table.th header-scope="row">{{ $documentNames[$key] ?? $key }}</x-wirekit::table.th>
                        @foreach ($locales as $locale)
                            @php($cell = $rows[$key][$locale])
                            <x-wirekit::table.td>
                                {{-- The whole cell is the link when an editor route is configured, and
                                     `underline="none"` is the reason it is readable: an underline drawn
                                     across a row of badges runs THROUGH each pill, which reads as struck
                                     out — the opposite of what every one of these states means. What is
                                     left is `cursor-pointer` and the hover fade the component already
                                     carries. A resting affordance is a border, and a border is CSS this
                                     package does not ship: publish this view and add one if your design
                                     calls for it.

                                     The accessible name repeats the states on purpose. An `aria-label`
                                     REPLACES the content it sits on, so a link announcing only "Edit
                                     Terms (de)" would hide the one fact the cell exists to state. --}}
                                @if ($cell['url'] !== null)
                                    <x-wirekit::link :href="$cell['url']" underline="none" :aria-label="$cell['label']">
                                @endif
                                @if (! $cell['written'])
                                    <x-wirekit::badge intent="neutral" size="sm" :aria-label="__('legal-consent::ui.admin_not_written')" :title="__('legal-consent::ui.admin_not_written')">{{ __('legal-consent::ui.admin_not_written_short') }}</x-wirekit::badge>
                                @else
                                    <x-wirekit::badge :intent="$cell['publishable'] ? 'success' : 'warning'" size="sm" :aria-label="__($cell['review_state_label'])" :title="__($cell['review_state_label'])">{{ __($cell['review_state_label'].'_short') }}</x-wirekit::badge>
                                    @if ($cell['machine'])
                                        <x-wirekit::badge intent="info" size="sm" :aria-label="__('legal-consent::ui.admin_machine')" :title="__('legal-consent::ui.admin_machine')">{{ __('legal-consent::ui.admin_machine_short') }}</x-wirekit::badge>
                                    @endif
                                    @if ($cell['stale'])
                                        <x-wirekit::badge intent="warning" size="sm" :aria-label="__('legal-consent::ui.admin_needs_update')" :title="__('legal-consent::ui.admin_needs_update')">{{ __('legal-consent::ui.admin_needs_update_short') }}</x-wirekit::badge>
                                    @elseif ($cell['unpublished_changes'])
                                        <x-wirekit::badge intent="neutral" size="sm" :aria-label="__('legal-consent::ui.admin_unpublished')" :title="__('legal-consent::ui.admin_unpublished')">{{ __('legal-consent::ui.admin_unpublished_short') }}</x-wirekit::badge>
                                    @endif
                                @endif
                                @if ($cell['url'] !== null)
                                    </x-wirekit::link>
                                @endif
                            </x-wirekit::table.td>
                        @endforeach
                    </x-wirekit::table.row>
                    {{-- Its own row, across all columns — see the plain twin for the measurement that
                         moved it: at 390 px the control sat 200 px past the right edge of the table's
                         visible area, and it is the only control this screen has. --}}
                    <x-wirekit::table.row wire:key="release-{{ $key }}">
                        <x-wirekit::table.td :colspan="count($locales) + 1">
                            @if ($rows[$key]['_release']['ready'])
                                {{-- The installed alert-dialog exposes a `trigger` slot
                                     plus a default-slot panel body built from alert-dialog.title /
                                     .description / .actions sub-components — NOT named title/description/
                                     confirm slots, which it silently drops (leaving an empty dialog with no
                                     confirm button, so the release is unreachable through the UI). --}}
                                <x-wirekit::alert-dialog :name="'lc-release-'.$key">
                                    <x-slot:trigger>
                                        <x-wirekit::button size="sm" :aria-label="__('legal-consent::ui.admin_release_all').' — '.($documentNames[$key] ?? $key)">{{ __('legal-consent::ui.admin_release_all') }}</x-wirekit::button>
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
                                <x-wirekit::button size="sm" disabled :aria-label="__('legal-consent::ui.admin_release_all').' — '.($documentNames[$key] ?? $key)">{{ __('legal-consent::ui.admin_release_all') }}</x-wirekit::button>
                                {{-- Grouped by REASON, one line each, because the per-locale form grew with
                                     the language count and the language count is what an application with
                                     seven of them cannot reduce. Measured in a browser at 1728 px with seven
                                     blocked locales: the cell was 486 px wide with nothing overflowing, and
                                     210 px TALL — six documents then show two rows per screen. Every locale
                                     still appears, beside the reason it shares, because an operator needs to
                                     know which language to go and fix. --}}
                                <x-wirekit::stack gap="xs" id="blocking-{{ $key }}">
                                    @foreach (\Pushery\LegalConsent\Support\BlockingReasonGroups::of($rows[$key]['_release']['blocking']) as $reason => $blockedLocales)
                                        <x-wirekit::text size="sm">{{ $reason }}: {{ implode(', ', $blockedLocales) }}</x-wirekit::text>
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
