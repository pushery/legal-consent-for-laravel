{{--
    WireKit-native variant of the "My consents" settings screen. Served automatically when
    `pushery/wirekit` is installed (`legal-consent.ui.variant`); publish with
    `--tag=legal-consent-wirekit` to customize it. Built from real `x-wirekit::*` components; needs
    `pushery/wirekit` in the host app and `@wirekitScripts` in the layout (the withdraw
    confirmation is an alert-dialog).

    The three blocks stay legally separate and must not be merged into one list: a contract is
    agreed, a privacy notice is only acknowledged ("zur Kenntnis genommen", never "ich willige
    ein"), and only a real consent is withdrawable (Art. 7(3)). Collapsing them would blur exactly
    the distinction this package exists to keep.

    $contracts / $acknowledgements / $consents:
        list{key, title, version, held, outstanding, withdrawable, url, withdraw_url,
        pending_confirmation}.

    `url` is null unless the host configured `legal-consent.document_url`. Where it is set the
    title becomes a link: a settings screen on which the document being withdrawn cannot be read
    is silent exactly where Art. 7(3) assumes the subject knows what they are deciding about.

    ⚠️ THIS IS THE TWIN OF THE FRAMEWORK-AGNOSTIC STUB, NOT OF THE LIVEWIRE VIEW. It is rendered on
    an ordinary page, so it withdraws with a FORM POST to `withdraw_url` — the bundled session
    route, switched on with `legal-consent.routes.web`. It used to carry `wire:click="withdraw(…)"`,
    copied from the Livewire twin, which on a page with no Livewire component behind it is inert:
    the dialog opens, the button is pressed, and nothing whatsoever happens. No error, no log, no
    failing test. The confirm button submits the form by its `form` attribute, so it may live
    inside the dialog while the form sits outside it.
--}}
<x-wirekit::stack gap="lg" class="legal-consent-settings">
    <x-wirekit::heading :level="2">{{ __('legal-consent::ui.settings_heading') }}</x-wirekit::heading>

    {{-- The result of the redirect the withdrawal route answers with.

         ⚠️ THE REGION IS INERT ON THIS PATH, and this comment used to claim the reverse. The
         withdrawal route answers POST with a redirect, so the message and the region carrying it
         arrive in the same fresh document — and a live region present at page load has no
         mutation to announce. That rationale belongs to the Livewire twin, which morphs its
         region in place. Kept because the roles still name the message in reading order, where it
         sits right after the heading. --}}
    <div role="status" aria-live="polite">
        @if (session('legal-consent.status'))
            <x-wirekit::text>{{ session('legal-consent.status') }}</x-wirekit::text>
        @endif
    </div>
    <div role="alert">
        @if (session('legal-consent.error'))
            <x-wirekit::text intent="danger">{{ session('legal-consent.error') }}</x-wirekit::text>
        @endif
    </div>

    {{-- The all-empty case gets ONE sentence instead of three headings over nothing. It is not the
         edge case it looks like: the groups come from the `legal_documents` table, which is empty
         until `legal-consent:publish` runs — so this is what every consumer sees between
         `composer require` and their first publish. --}}
    @if (count($contracts) === 0 && count($acknowledgements) === 0 && count($consents) === 0)
        <x-wirekit::text intent="muted">{{ __('legal-consent::ui.nothing_published') }}</x-wirekit::text>
    @else

    <x-wirekit::stack gap="sm" as="section" aria-labelledby="lc-contracts">
        <x-wirekit::heading :level="3" id="lc-contracts">{{ __('legal-consent::ui.contracts_heading') }}</x-wirekit::heading>
        <x-wirekit::stack gap="xs">
            @forelse ($contracts as $item)
                {{-- The title can be in a language this page is not in — a document published
                     only in the default locale still binds, and a RETIRED row is deliberately shown
                     in the language the subject read it in. Without `lang` a screen reader speaks it
                     with the page's phonetics (WCAG 3.1.2). See the plain stub, and
                     ContentLanguage for the rule. --}}
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
                {{-- The title can be in a language this page is not in — a document published
                     only in the default locale still binds, and a RETIRED row is deliberately shown
                     in the language the subject read it in. Without `lang` a screen reader speaks it
                     with the page's phonetics (WCAG 3.1.2). See the plain stub, and
                     ContentLanguage for the rule. --}}
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
                {{-- Same as the two blocks above: the title's own language, declared only where it
                     differs from the page. See ContentLanguage. --}}
                @php($lang = \Pushery\LegalConsent\Support\ContentLanguage::differingFrom($item['locale'] ?? null))
                <x-wirekit::stack gap="sm" :wrap="true" class="legal-consent-settings__consent">
                    <x-wirekit::text>
                        @if (($item['url'] ?? null) !== null)
                            <x-wirekit::link :href="$item['url']" :hreflang="$lang" :lang="$lang" external>{{ $item['title'] }}</x-wirekit::link>
                        @else
                            <span @if ($lang !== null) lang="{{ $lang }}" @endif>{{ $item['title'] }}</span>
                        @endif
                    </x-wirekit::text>

                    {{-- A document retired out from under a holding. `is_active = false` does not
                         end the consents recorded against it, so the row stays with its withdrawal
                         control intact — but it is never `outstanding`, and without this the
                         subject cannot see why the version is older than the one they read about. --}}
                    @if ($item['retired'] ?? false)
                        <x-wirekit::badge intent="neutral" size="sm">{{ __('legal-consent::ui.retired') }}</x-wirekit::badge>
                    @endif

                    {{-- The double opt-in's middle state: entered but not yet confirmed reads as
                         never entered otherwise, and the screen would say nothing at all. --}}
                    @if ($item['pending_confirmation'] ?? false)
                        <x-wirekit::badge intent="neutral" size="sm">{{ __('legal-consent::ui.confirmation_pending') }}</x-wirekit::badge>
                    @endif

                    @if (($item['withdraw_url'] ?? null) !== null)
                        {{-- The form carries the POST; the dialog's confirm button submits it by id.
                             A control with no action is not a control, so the whole block is absent
                             while `legal-consent.routes.web` is off or the entry is not a
                             withdrawable consent the subject holds. --}}
                        <form method="post" action="{{ $item['withdraw_url'] }}" id="lc-withdraw-form-{{ $item['key'] }}" class="legal-consent-withdraw-form">
                            @csrf
                            <input type="hidden" name="document_key" value="{{ $item['key'] }}">
                        </form>

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

                                <x-wirekit::button intent="danger" type="submit" form="lc-withdraw-form-{{ $item['key'] }}">
                                    {{ __('legal-consent::ui.withdraw') }}
                                </x-wirekit::button>
                            </x-wirekit::alert-dialog.actions>
                        </x-wirekit::alert-dialog>
                    @endif
                </x-wirekit::stack>
            @empty
                <x-wirekit::text intent="muted">{{ __('legal-consent::ui.consents_empty') }}</x-wirekit::text>
            @endforelse
        </x-wirekit::stack>
    </x-wirekit::stack>
    @endif
</x-wirekit::stack>
