<div>
    {{-- A named landmark region so the whole admin surface is reachable by assistive tech. --}}
    <section aria-labelledby="legal-text-manager-heading">
        <h1 id="legal-text-manager-heading">Legal texts</h1>

        {{-- Status: an assertive live region, so a screen reader hears the result of a release (or
             why it did not happen) immediately after the action — WCAG 4.1.3. It is always present
             in the DOM (an aria-live region added at the same time as its text is not announced). --}}
        <p role="alert" aria-live="assertive" wire:key="legal-text-manager-status">{{ $status }}</p>

        <p>Texts are edited per locale, reviewed by a human, then released across every locale at once.
            A machine translation can never be published until someone reviews it, and the acceptance
            sentence is fixed vendor copy — it is never machine-translated.</p>

        {{-- The grid has Document + one column per locale + Release, so it can exceed a narrow
             viewport. A keyboard-focusable horizontal-scroll region keeps it reachable without
             forcing whole-page horizontal scrolling (WCAG 1.4.10 Reflow). --}}
        <div role="region" aria-label="{{ __('legal-consent::ui.admin_heading') }}" tabindex="0" style="overflow-x: auto;">
        <table>
            <thead>
                <tr>
                    <th scope="col">Document</th>
                    @foreach ($locales as $locale)
                        <th scope="col">{{ $locale }}</th>
                    @endforeach
                    <th scope="col">Release</th>
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
                                    <span>Not written</span>
                                @else
                                    <span>{{ $cell['review_state'] }}</span>
                                    @if ($cell['machine'])
                                        <span> · machine-drafted</span>
                                    @endif
                                    @if ($cell['stale'])
                                        <span> · needs update</span>
                                    @elseif ($cell['unpublished_changes'])
                                        <span> · unpublished changes</span>
                                    @endif
                                @endif
                                {{-- Placeholder link: the package ships no admin routes, so wire
                                     href to your own editor route. The per-cell aria-label keeps a
                                     screen-reader link list from reading "edit, edit, edit…". --}}
                                <a href="#" wire:navigate aria-label="Edit {{ $key }} ({{ $locale }})">edit</a>
                            </td>
                        @endforeach
                        <td>
                            @if ($rows[$key]['_release']['ready'])
                                <button type="button" wire:click="releaseAll('{{ $key }}')">Release all locales</button>
                            @else
                                <button type="button" disabled aria-describedby="blocking-{{ $key }}">Release all locales</button>
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
