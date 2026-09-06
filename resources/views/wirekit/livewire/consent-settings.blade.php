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

        {{-- The all-empty case gets ONE sentence instead of three headings over nothing. It is not
             the edge case it looks like: the groups are built from the `legal_documents` table,
             which is empty until `legal-consent:publish` runs — so this is what every consumer sees
             between `composer require` and their first publish. --}}
        @if (count($contracts) === 0 && count($acknowledgements) === 0 && count($consents) === 0)
            <x-wirekit::text intent="muted">{{ __('legal-consent::ui.nothing_published') }}</x-wirekit::text>
        @else

        <x-wirekit::stack gap="sm" as="section" aria-labelledby="lc-contracts">
            <x-wirekit::heading :level="3" id="lc-contracts">{{ __('legal-consent::ui.contracts_heading') }}</x-wirekit::heading>
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

        <x-wirekit::stack gap="sm" as="section" aria-labelledby="lc-acknowledgements">
            <x-wirekit::heading :level="3" id="lc-acknowledgements">{{ __('legal-consent::ui.acknowledgements_heading') }}</x-wirekit::heading>
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

        <x-wirekit::stack gap="sm" as="section" aria-labelledby="lc-consents">
            <x-wirekit::heading :level="3" id="lc-consents">{{ __('legal-consent::ui.consents_heading') }}</x-wirekit::heading>
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

                                    {{-- `{{ Js::from() }}`, NOT `@js()`. A Blade directive inside a
                                         COMPONENT TAG attribute is never compiled — the tag compiler
                                         lifts the attribute out as a literal before the directive
                                         compiler reaches it, so the text `@js($item['key'])` is what
                                         the browser gets and the withdrawal never fires, for every
                                         key and with nothing logged. On Art. 7(3) that is the one
                                         control that must not be decorative. An echo IS compiled in
                                         that position and emits what `@js()` emits on a plain
                                         element, which is the form the plain stub uses. --}}
                                    <x-wirekit::button intent="danger" wire:click="withdraw({{ \Illuminate\Support\Js::from($item['key']) }})" wire:loading.attr="aria-busy" wire:target="withdraw">
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
                            <x-wirekit::button surface="outline" wire:click="grant({{ \Illuminate\Support\Js::from($item['key']) }})" wire:loading.attr="aria-busy" wire:target="grant" :aria-label="__('legal-consent::ui.grant_for', ['title' => $item['title']])">
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
    </x-wirekit::stack>
</div>
