<div>
    {{-- A named landmark region so the whole admin surface is reachable by assistive tech. --}}
    <section aria-labelledby="legal-text-manager-heading">
        <h1 id="legal-text-manager-heading">{{ __('legal-consent::ui.admin_heading') }}</h1>

        {{-- Status: an assertive live region, so a screen reader hears the result of a release (or
             why it did not happen) immediately after the action — WCAG 4.1.3. It is always present
             in the DOM (an aria-live region added at the same time as its text is not announced). --}}
        <p role="alert" aria-live="assertive" wire:key="legal-text-manager-status"><span wire:key="legal-text-manager-status-{{ $statusNonce }}">{{ $status }}</span></p>

        <p>{{ __('legal-consent::ui.admin_policy') }}</p>

        {{-- The grid has Document + one column per locale + Release, so it can exceed a narrow
             viewport. A keyboard-focusable horizontal-scroll region keeps it reachable without
             forcing whole-page horizontal scrolling (WCAG 1.4.10 Reflow). --}}
        <div role="region" aria-label="{{ __('legal-consent::ui.admin_heading') }}" tabindex="0" style="overflow-x: auto;">
        <table>
            <thead>
                <tr>
                    <th scope="col">{{ __('legal-consent::ui.admin_document') }}</th>
                    @foreach ($locales as $locale)
                        <th scope="col">{{ $locale }}</th>
                    @endforeach
                    <th scope="col">{{ __('legal-consent::ui.admin_release') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($keys as $key)
                    <tr wire:key="row-{{ $key }}">
                        <th scope="row">{{ $key }}</th>
                        @foreach ($locales as $locale)
                            @php($cell = $rows[$key][$locale])
                            <td>
                                @if (! $cell['written'])
                                    <span>{{ __('legal-consent::ui.admin_not_written') }}</span>
                                @else
                                    <span>{{ $cell['review_state'] }}</span>
                                    @if ($cell['machine'])
                                        <span> · {{ __('legal-consent::ui.admin_machine') }}</span>
                                    @endif
                                    @if ($cell['stale'])
                                        <span> · {{ __('legal-consent::ui.admin_needs_update') }}</span>
                                    @elseif ($cell['unpublished_changes'])
                                        <span> · {{ __('legal-consent::ui.admin_unpublished') }}</span>
                                    @endif
                                @endif
                                {{-- Placeholder link: the package ships no admin routes, so wire href
                                     to your own editor route. The per-cell aria-label keeps a screen
                                     reader's link list from reading "edit, edit, edit…". --}}
                                <a href="#" wire:navigate aria-label="{{ __('legal-consent::ui.admin_edit_for', ['key' => $key, 'locale' => $locale]) }}">{{ __('legal-consent::ui.admin_edit') }}</a>
                            </td>
                        @endforeach
                        <td>
                            @if ($rows[$key]['_release']['ready'])
                                {{-- Per-document accessible name: with N rows, N buttons all reading
                                     "Release all locales" are indistinguishable in a screen reader's
                                     button list (WCAG 2.4.6) — the same rule this package already
                                     enforces for the withdraw control. --}}
                                <button type="button" aria-label="{{ __('legal-consent::ui.admin_release_all').' — '.$key }}" wire:click="releaseAll('{{ $key }}')">{{ __('legal-consent::ui.admin_release_all') }}</button>
                            @else
                                {{-- The blocking reasons stay OUTSIDE aria-describedby on a disabled
                                     button (a disabled control is skipped, so its description is never
                                     announced) — they are rendered as visible text below instead. --}}
                                <button type="button" disabled aria-label="{{ __('legal-consent::ui.admin_release_all').' — '.$key }}">{{ __('legal-consent::ui.admin_release_all') }}</button>
                                <ul id="blocking-{{ $key }}">
                                    @foreach ($rows[$key]['_release']['blocking'] as $locale => $reason)
                                        <li>{{ $locale }}: {{ $reason }}</li>
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
