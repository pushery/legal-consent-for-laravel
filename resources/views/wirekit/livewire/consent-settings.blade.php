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
        {{-- Left out where the embedding page titles itself (`:heading="false"`), and the group headings
             below then move up to the level it leaves free, so the outline keeps no gap under the page's
             own heading. `?? true` for a render outside the component, which passes no such flag. --}}
        @php($groupLevel = ($heading ?? true) ? 3 : 2)
        @if ($heading ?? true)
            <x-wirekit::heading :level="2">{{ __('legal-consent::ui.settings_heading') }}</x-wirekit::heading>
        @endif

        {{-- WCAG 4.1.3 + 2.4.3: the withdrawal result is announced here — the region is ALWAYS in the
             DOM (a live region inserted together with its text is not announced) and role="status"
             carries the announcement, so focus lands on the live region itself, exactly as in the
             plain view. The text is a plain x-wirekit::text, NOT an x-wirekit::alert (the alert is
             itself a role="status" region — nesting would double-announce). Focus is driven by
             x-effect, not x-init: x-init runs once and does not re-run on a Livewire morph.

             `sr-only` while it is EMPTY, and only then. An empty region has no height but is still a
             child of the stack, so it added a second `lg` gap under the heading. Visually hidden it
             stays in the DOM and in the accessibility tree, which is what the announcement needs, but
             it is positioned out of the stack's flow and takes no gap; with a status in it, it is an
             ordinary child again. --}}
        <div role="status" aria-live="polite" tabindex="-1" wire:key="lc-settings-status"
            @class(['sr-only' => ($status ?? '') === ''])
            x-effect="$wire.statusNonce > 0 && $el.focus()">
            @if (($status ?? '') !== '')
                <div wire:key="lc-settings-status-{{ $statusNonce }}"><x-wirekit::text>{{ $status }}</x-wirekit::text></div>
            @endif
        </div>

        {{-- The all-empty case gets one empty state instead of three headings over nothing. It is not
             the edge case it looks like: the groups are built from the `legal_documents` table,
             which is empty until `legal-consent:publish` runs — so this is what every consumer sees
             between `composer require` and their first publish. An empty state rather than a muted
             line, because a line alone in the panel reads as something that failed to load, and the
             description says what will appear here. Its title takes the level the groups would have. --}}
        @php($hideEmpty = $hideEmptyGroups ?? false)
        @if (count($contracts) === 0 && count($acknowledgements) === 0 && count($consents) === 0)
            <x-wirekit::empty-state :level="$groupLevel" :title="__('legal-consent::ui.nothing_published_title')" :description="__('legal-consent::ui.nothing_published_description')" />
        @else

        {{-- `:hide-empty-groups="true"` leaves out a group with nothing in it, for a deployment that never
             publishes that kind of document and would otherwise show it empty to every subject. --}}
        @if (! $hideEmpty || count($contracts) > 0)

        <x-wirekit::stack gap="sm" as="section" aria-labelledby="lc-contracts">
            <x-wirekit::heading :level="$groupLevel" id="lc-contracts">{{ __('legal-consent::ui.contracts_heading') }}</x-wirekit::heading>
            <x-wirekit::stack gap="xs">
                @forelse ($contracts as $item)
                    {{-- The title's own language, declared only where it differs from the page. A
                         document published only in the default locale still binds, and a RETIRED
                         row is deliberately shown in the language the subject read it in
                         (WCAG 3.1.2). See ContentLanguage. --}}
                    @php($lang = \Pushery\LegalConsent\Support\ContentLanguage::differingFrom($item['locale'] ?? null))
                    <x-wirekit::text>
                        @if (($item['url'] ?? null) !== null)
                            <x-wirekit::link :href="$item['url']" :hreflang="$lang" :lang="$lang" external>{{ $item['title'] }}</x-wirekit::link>
                        @else
                            <span @if ($lang !== null) lang="{{ $lang }}" @endif>{{ $item['title'] }}</span>
                        @endif
                        <x-wirekit::badge intent="neutral" size="sm">v{{ $item['version'] }}</x-wirekit::badge> @if (($item['outstanding'] ?? false)) <x-wirekit::badge intent="warning" size="sm">{{ __('legal-consent::ui.action_required') }}</x-wirekit::badge> @endif
                    </x-wirekit::text>
                @empty
                    <x-wirekit::text intent="muted">{{ __('legal-consent::ui.contracts_empty') }}</x-wirekit::text>
                @endforelse
            </x-wirekit::stack>
        </x-wirekit::stack>
        @endif

        @if (! $hideEmpty || count($acknowledgements) > 0)
        <x-wirekit::stack gap="sm" as="section" aria-labelledby="lc-acknowledgements">
            <x-wirekit::heading :level="$groupLevel" id="lc-acknowledgements">{{ __('legal-consent::ui.acknowledgements_heading') }}</x-wirekit::heading>
            <x-wirekit::stack gap="xs">
                @forelse ($acknowledgements as $item)
                    {{-- The title's own language, declared only where it differs from the page. A
                         document published only in the default locale still binds, and a RETIRED
                         row is deliberately shown in the language the subject read it in
                         (WCAG 3.1.2). See ContentLanguage. --}}
                    @php($lang = \Pushery\LegalConsent\Support\ContentLanguage::differingFrom($item['locale'] ?? null))
                    <x-wirekit::text>
                        @if (($item['url'] ?? null) !== null)
                            <x-wirekit::link :href="$item['url']" :hreflang="$lang" :lang="$lang" external>{{ $item['title'] }}</x-wirekit::link>
                        @else
                            <span @if ($lang !== null) lang="{{ $lang }}" @endif>{{ $item['title'] }}</span>
                        @endif
                        <x-wirekit::badge intent="neutral" size="sm">v{{ $item['version'] }}</x-wirekit::badge> @if (($item['outstanding'] ?? false)) <x-wirekit::badge intent="warning" size="sm">{{ __('legal-consent::ui.action_required') }}</x-wirekit::badge> @endif
                    </x-wirekit::text>
                @empty
                    <x-wirekit::text intent="muted">{{ __('legal-consent::ui.acknowledgements_empty') }}</x-wirekit::text>
                @endforelse
            </x-wirekit::stack>
        </x-wirekit::stack>
        @endif

        {{-- Icons for the panel's buttons, from `legal-consent.ui.icons`. Unset, a button carries none. An
             application that gives every action an icon had no way to give these two one. --}}
        @php($icons = ['withdraw' => config('legal-consent.ui.icons.withdraw'), 'grant' => config('legal-consent.ui.icons.grant')])

        @if (! $hideEmpty || count($consents) > 0)
        <x-wirekit::stack gap="sm" as="section" aria-labelledby="lc-consents">
            <x-wirekit::heading :level="$groupLevel" id="lc-consents">{{ __('legal-consent::ui.consents_heading') }}</x-wirekit::heading>
            <x-wirekit::stack gap="xs">
                @forelse ($consents as $item)
                    {{-- Same rule as the two blocks above. See ContentLanguage. --}}
                    @php($lang = \Pushery\LegalConsent\Support\ContentLanguage::differingFrom($item['locale'] ?? null))
                    <x-wirekit::stack gap="sm" :wrap="true" class="legal-consent-settings__consent">
                        <x-wirekit::text>
                            @if (($item['url'] ?? null) !== null)
                                <x-wirekit::link :href="$item['url']" :hreflang="$lang" :lang="$lang" external>{{ $item['title'] }}</x-wirekit::link>
                            @else
                                <span @if ($lang !== null) lang="{{ $lang }}" @endif>{{ $item['title'] }}</span>
                            @endif
                            {{-- The state in words, beside the title and whatever the action next to it is.
                                 A screen that offers no Give button showed a consent that is not given as a
                                 bare title, and one that is given only through the Withdraw button beside it.
                                 Neutral for both: the screen reports a position, it does not steer one. --}}
                            <x-wirekit::badge intent="neutral" size="sm" class="legal-consent-settings__state">{{ $item['held'] ? __('legal-consent::ui.consent_given') : __('legal-consent::ui.consent_not_given') }}</x-wirekit::badge>
                        </x-wirekit::text>

                        @if ($item['held'] && $item['withdrawable'])
                            {{-- A withdrawal is irreversible: it appends a Withdrawn row to an
                                 append-only ledger. That earns a real confirmation — an
                                 alert-dialog, never the unstyled native browser confirm. --}}
                            <x-wirekit::alert-dialog :name="'lc-withdraw-'.$item['key']">
                                <x-slot:trigger>
                                    <x-wirekit::button intent="danger" surface="outline" :aria-label="__('legal-consent::ui.withdraw_for', ['title' => $item['title']])">
                                        @if (filled($icons['withdraw']))
                                            <x-slot:iconLeft><x-wirekit::icon :name="$icons['withdraw']" /></x-slot:iconLeft>
                                        @endif
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

                                    {{-- `{{ Js::from() }}`, NOT `@js()`. A Blade directive inside a
                                         COMPONENT TAG attribute is never compiled — the tag compiler
                                         lifts the attribute out as a literal before the directive
                                         compiler reaches it, so the text `@js($item['key'])` is what
                                         the browser gets and the withdrawal never fires, for every
                                         key and with nothing logged. On Art. 7(3) that is the one
                                         control that must not be decorative. An echo IS compiled in
                                         that position and emits what `@js()` emits on a plain
                                         element, which is the form the plain stub uses. --}}
                                    <x-wirekit::button intent="danger" wire:click="withdraw({{ \Illuminate\Support\Js::from($item['key']) }})" loading-target="withdraw" :disable-on-loading="false">
                                        @if (filled($icons['withdraw']))
                                            <x-slot:iconLeft><x-wirekit::icon :name="$icons['withdraw']" /></x-slot:iconLeft>
                                        @endif
                                        {{ __('legal-consent::ui.withdraw') }}
                                    </x-wirekit::button>
                                </x-wirekit::alert-dialog.actions>
                            </x-wirekit::alert-dialog>
                        {{-- A document retired out from under a holding: `is_active = false` does
                             not end the consents recorded against it, and the row keeps its
                             withdrawal control. It takes the Give button's place because there is
                             nothing left to give — the version is no longer published. --}}
                        @elseif ($item['retired'] ?? false)
                            <x-wirekit::badge intent="neutral" size="sm">{{ __('legal-consent::ui.retired') }}</x-wirekit::badge>
                        {{-- The double opt-in's middle state, and it takes the place of the Give
                             button rather than sitting beside it: giving again would write a
                             second request, which supersedes the first and stops the confirmation
                             link already in the subject's inbox from working. --}}
                        @elseif ($item['pending_confirmation'] ?? false)
                            <x-wirekit::badge intent="neutral" size="sm">{{ __('legal-consent::ui.confirmation_pending') }}</x-wirekit::badge>
                        {{-- The counterpart, and only where the embedding screen asked for it.
                             Deliberately NOT behind an alert-dialog: giving a voluntary consent is
                             reversible in one click on this very screen, so a confirmation would
                             put friction on the harmless direction and none on the irreversible
                             one — the exact opposite of what Art. 7(3) is about. --}}
                        @elseif (! $item['held'] && $this->allowGrant)
                            {{-- An echo, not `@js()` — see the withdraw button above for why the
                                 directive never compiles in a component tag attribute. --}}
                            <x-wirekit::button surface="outline" wire:click="grant({{ \Illuminate\Support\Js::from($item['key']) }})" loading-target="grant" :disable-on-loading="false" :aria-label="__('legal-consent::ui.grant_for', ['title' => $item['title']])">
                                @if (filled($icons['grant']))
                                    <x-slot:iconLeft><x-wirekit::icon :name="$icons['grant']" /></x-slot:iconLeft>
                                @endif
                                {{ __('legal-consent::ui.grant') }}
                            </x-wirekit::button>
                        @endif
                    </x-wirekit::stack>
                @empty
                    <x-wirekit::text intent="muted">{{ __('legal-consent::ui.consents_empty') }}</x-wirekit::text>
                @endforelse
            </x-wirekit::stack>
        </x-wirekit::stack>
        @endif
        @endif
    </x-wirekit::stack>
</div>
