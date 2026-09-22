<div>
    {{-- A named landmark region so the whole admin surface is reachable by assistive tech. --}}
    {{-- Left out where the embedding page titles itself (`:heading="false"`), the same switch the
         consent panel carries. The landmark then takes its name DIRECTLY rather than pointing at a
         heading that is no longer in the document: `aria-labelledby` at a missing id names nothing,
         and an unnamed region is not an improvement on a duplicated title.
         `?? true` for a render outside the component, which passes no such flag. --}}
    <section @if ($heading ?? true) aria-labelledby="legal-text-manager-heading" @else aria-label="{{ __('legal-consent::ui.admin_heading') }}" @endif>
        @if ($heading ?? true)
            <h1 id="legal-text-manager-heading">{{ __('legal-consent::ui.admin_heading') }}</h1>
        @endif

        {{-- Status: a live region, so a screen reader hears the result of a release (or why it did
             not happen) right after the action — WCAG 4.1.3. It is always present in the DOM (an
             aria-live region added at the same time as its text is not announced).

             tabindex="-1" + x-effect is the FOCUS half, and it is not decoration here. The release
             is confirmed in a modal that closes itself on click; without somewhere to send focus it
             lands on <body>, which is WCAG 2.4.3 and leaves a keyboard user at the top of the
             document after an irreversible action. wire:key alone cannot do it: the element is
             always present and Livewire morphs it rather than replacing it, so nothing re-runs.
             x-effect re-runs whenever $wire.statusNonce changes — exactly when a release sets it.
             Same pattern as consent-settings and reconsent-form; this screen was the one left out. --}}
        {{-- `status`/`polite`, not `alert`/`assertive`, and the focus move is why. `role="alert"`
             implies an assertive live region, and this same element also receives focus — so
             assistive technology says the message TWICE: once as a live-region interruption, once
             as the name of the newly focused element. Dropping the focus move instead would send a
             keyboard user to <body> after an irreversible action (WCAG 2.4.3), which is worse.
             The focus move is already immediate, so politeness costs no urgency.
             This also brings the screen in line with the other three status regions of the package,
             which have used `status` + focus all along. The WireKit twin was raised to `assertive`
             to match THIS one; both are lowered together, because two twins of one screen must not
             disagree about how loudly a release is announced. --}}
        <p role="status" aria-live="polite" tabindex="-1" wire:key="legal-text-manager-status"
            x-effect="$wire.statusNonce > 0 && $el.focus()"><span wire:key="legal-text-manager-status-{{ $statusNonce }}">{{ $status }}</span></p>

        <p>{{ __('legal-consent::ui.admin_policy') }}</p>

        {{-- The grid has Document + one column per locale + Release, so it can exceed a narrow
             viewport. A keyboard-focusable horizontal-scroll region keeps it reachable without
             forcing whole-page horizontal scrolling (WCAG 1.4.10 Reflow). --}}
        <div role="region" aria-label="{{ __('legal-consent::ui.admin_heading') }}" tabindex="0" style="overflow-x: auto;">
        {{-- The legend. This matrix carries one column per locale, so the widest state word
             decides the table's width -- and the words are sentences. The cell carries the short
             form, its `title` the long one, and this line explains the six once for a reader who
             has no pointer to hover with. The WireKit twin does the same with an accessible name,
             which a `title` alone is not. --}}
        <p>
            @foreach ([
                'admin_not_written',
                'review_state_draft',
                'review_state_reviewed',
                'admin_machine',
                'admin_needs_update',
                'admin_unpublished',
            ] as $state){{ __('legal-consent::ui.'.$state.'_short') }} = {{ __('legal-consent::ui.'.$state) }}@if (! $loop->last) · @endif @endforeach
        </p>

        {{-- What the two review words MEAN, which the line above cannot say: it maps a short form
             onto a long one, so "Draft" explains itself with "Draft". The half that matters is the
             second sentence of the first -- reviewing publishes NOTHING. On the one screen whose
             product is that a person vouched for these exact bytes, "Reviewed" reading as "done,
             live" is the most expensive confusion available, and it goes wrong in both directions:
             a text believed live that is not, or a release pressed because it seemed to have
             happened already. The second answers the question that follows immediately -- why the
             release is held while my own language is finished -- and that answer lived only behind
             the confirm dialog, which nobody opens while they still have the question.

             Override either with an empty string to drop it, the same way every other ui.* key
             here can be overridden. --}}
        @foreach (['review_state_reviewed', 'review_state_draft'] as $state)
            @if (__('legal-consent::ui.'.$state.'_description') !== '')
                <p>{{ __('legal-consent::ui.'.$state) }}: {{ __('legal-consent::ui.'.$state.'_description') }}</p>
            @endif
        @endforeach

        <table>
            <thead>
                <tr>
                    <th scope="col">{{ __('legal-consent::ui.admin_document') }}</th>
                    @foreach ($locales as $locale)
                        <th scope="col">{{ $locale }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($keys as $key)
                    <tr wire:key="row-{{ $key }}">
                        <th scope="row">{{ $documentNames[$key] ?? $key }}</th>
                        @foreach ($locales as $locale)
                            @php($cell = $rows[$key][$locale])
                            <td>
                                @if ($cell['url'] !== null)
                                    <a href="{{ $cell['url'] }}" aria-label="{{ $cell['label'] }}">
                                @endif
                                @if (! $cell['written'])
                                    <span title="{{ __('legal-consent::ui.admin_not_written') }}">{{ __('legal-consent::ui.admin_not_written_short') }}</span>
                                @else
                                    <span title="{{ __($cell['review_state_label']) }}">{{ __($cell['review_state_label'].'_short') }}</span>
                                    @if ($cell['machine'])
                                        <span title="{{ __('legal-consent::ui.admin_machine') }}"> · {{ __('legal-consent::ui.admin_machine_short') }}</span>
                                    @endif
                                    @if ($cell['stale'])
                                        <span title="{{ __('legal-consent::ui.admin_needs_update') }}"> · {{ __('legal-consent::ui.admin_needs_update_short') }}</span>
                                    @elseif ($cell['unpublished_changes'])
                                        <span title="{{ __('legal-consent::ui.admin_unpublished') }}"> · {{ __('legal-consent::ui.admin_unpublished_short') }}</span>
                                    @endif
                                @endif
                                @if ($cell['url'] !== null)
                                    </a>
                                @endif
                                {{-- Nothing is rendered until `admin.editor_route` names a route, and
                                     that is unchanged rather than a leftover: the package brings no
                                     admin routes, so with no name configured anything here would have
                                     to point at `#` — a link with a fully worded promise standing in a
                                     screen reader's link list, one per cell, that moves focus to the
                                     top of the document when activated. `wire:navigate` would make it
                                     worse rather than inert: Livewire decides natively on protocol,
                                     origin, `download` and `target` alone, so a hash href is same-origin
                                     http(s), Livewire takes over, and it runs a full fetch and DOM morph
                                     against the page the visitor is already on.

                                     With a name configured the href is real, so none of that applies —
                                     and the accessible name names the document, the locale and the
                                     states, because an `aria-label` replaces the content it sits on. --}}
                            </td>
                        @endforeach
                    </tr>
                    {{-- Its own row, across all columns, and the reason is a viewport nobody had
                         measured. As the last COLUMN of a grid that is documents x locales, the only
                         control on this screen sat 200 px past the right edge of the visible table at
                         390 px wide — reachable solely by scrolling the table sideways, with the
                         reasons a blocked release gives sitting out there beside it.

                         The earlier report against a row here was about HEIGHT, measured at 1728 px
                         with one full sentence per language. Grouping the locales under the reason
                         they share settled that, and the row is short now.

                         `colspan` counts the locale columns plus the document column, and the release
                         column header is gone with the cell — which is also what shrank the grid: at
                         seven locales it now overflows its scroller by 35 px instead of 530.

                         A `position: sticky` hold was here and is GONE, because a red probe showed
                         it did nothing. Measured at fourteen locales, scrolled to the far end: the
                         control sat at -115 px with the hold in place, exactly as without it. Shipping
                         a declaration that cannot be shown to act is worse than shipping none. --}}
                    <tr wire:key="release-{{ $key }}">
                        <td colspan="{{ count($locales) + 1 }}">
                            @if ($rows[$key]['_release']['ready'])
                                {{-- Per-document accessible name: with N rows, N buttons all reading
                                     "Release all locales" are indistinguishable in a screen reader's
                                     button list (WCAG 2.4.6) — the same rule this package already
                                     enforces for the withdraw control. --}}
                                <button type="button" aria-label="{{ __('legal-consent::ui.admin_release_all').' — '.($documentNames[$key] ?? $key) }}" wire:click="releaseAll(@js($key))" wire:loading.attr="aria-busy" wire:target="releaseAll">{{ __('legal-consent::ui.admin_release_all') }}</button>
                            @else
                                {{-- The blocking reasons stay OUTSIDE aria-describedby on a disabled
                                     button (a disabled control is skipped, so its description is never
                                     announced) — they are rendered as visible text below instead.
                                     The id below is therefore a styling hook only. It is deliberately
                                     NOT an aria target: pointing a describedby at it would restore the
                                     association this comment exists to prevent. --}}
                                <button type="button" disabled aria-label="{{ __('legal-consent::ui.admin_release_all').' — '.($documentNames[$key] ?? $key) }}">{{ __('legal-consent::ui.admin_release_all') }}</button>
                                {{-- Grouped by REASON, for the measurement recorded in the WireKit twin:
                                     per-locale lines made a blocked row 210 px tall at seven locales, and
                                     nothing about the column was ever too narrow. Every locale still
                                     appears, beside the reason it shares. --}}
                                <ul id="blocking-{{ $key }}">
                                    @foreach (\Pushery\LegalConsent\Support\BlockingReasonGroups::of($rows[$key]['_release']['blocking']) as $reason => $blockedLocales)
                                        <li>{{ $reason }}: {{ implode(', ', $blockedLocales) }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </section>
</div>
