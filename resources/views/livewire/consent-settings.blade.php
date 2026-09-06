{{--
    View for the ConsentSettings Livewire component. Single root element (Livewire
    requirement). The three legally distinct blocks stay separate; only a real consent
    (withdrawable) shows a one-click withdraw control (Art. 7(3)). Style freely.
--}}
<div class="legal-consent-settings">
    <h2>{{ __('legal-consent::ui.settings_heading') }}</h2>

    {{-- WCAG 4.1.3 + 2.4.3: after a withdrawal the row's button is gone, so the result is announced
         here (a polite live region, always present so the update is spoken) and focus moves to it so
         it does not drop to <body>. Focus is driven by x-effect, not x-init: x-init runs once on an
         always-present element and does NOT re-run when Livewire morphs it, so the focus never moved.
         x-effect re-runs whenever $wire.status changes, i.e. exactly when a withdrawal sets it. --}}
    <p role="status" aria-live="polite" tabindex="-1" wire:key="lc-settings-status"
        x-effect="$wire.statusNonce > 0 && $el.focus()"><span wire:key="lc-settings-status-{{ $statusNonce }}">{{ $status ?? '' }}</span></p>

    {{-- The all-empty case gets ONE sentence instead of three headings over nothing. It is not the
         edge case it looks like: the groups are built from the `legal_documents` table, which is
         empty until `legal-consent:publish` runs — so this is what every consumer sees between
         `composer require` and their first publish, and three bare headings read as broken. --}}
    @if (count($contracts) === 0 && count($acknowledgements) === 0 && count($consents) === 0)
        <p class="legal-consent-empty">{{ __('legal-consent::ui.nothing_published') }}</p>
    @else

    {{-- Each title links to the document when the host configured `legal-consent.document_url`,
         and renders as plain text otherwise. Deciding to withdraw a consent without being able to
         re-read what was consented to is the one thing this screen must not ask of anyone
         (Art. 7(3): as easy to withdraw as to give). --}}
    <section aria-labelledby="lc-contracts">
        <h3 id="lc-contracts">{{ __('legal-consent::ui.contracts_heading') }}</h3>
        <ul>
            @forelse ($contracts as $item)
                {{-- The title's own language, declared only where it differs from the page: a
                     document published only in the default locale still binds, and a RETIRED row is
                     deliberately shown in the language the subject read it in. Without `lang` a
                     screen reader speaks it with the page's phonetics (WCAG 3.1.2). `hreflang` is a
                     statement about the far end of the link and does not replace it. --}}
                @php($lang = \Pushery\LegalConsent\Support\ContentLanguage::differingFrom($item['locale'] ?? null))
                <li>
                    @if (($item['url'] ?? null) !== null)
                        <a href="{{ $item['url'] }}"@if ($lang !== null) hreflang="{{ $lang }}" lang="{{ $lang }}"@endif target="_blank" rel="noopener noreferrer">{{ $item['title'] }}</a>
                    @else
                        <span @if ($lang !== null) lang="{{ $lang }}"@endif>{{ $item['title'] }}</span>
                    @endif
                    (v{{ $item['version'] }}) @if (($item['outstanding'] ?? false)) <strong class="legal-consent-action-required">{{ __('legal-consent::ui.action_required') }}</strong> @endif
                </li>
            @empty
                <li class="legal-consent-empty">{{ __('legal-consent::ui.contracts_empty') }}</li>
            @endforelse
        </ul>
    </section>

    <section aria-labelledby="lc-acknowledgements">
        <h3 id="lc-acknowledgements">{{ __('legal-consent::ui.acknowledgements_heading') }}</h3>
        <ul>
            @forelse ($acknowledgements as $item)
                {{-- The title's own language, declared only where it differs from the page: a
                     document published only in the default locale still binds, and a RETIRED row is
                     deliberately shown in the language the subject read it in. Without `lang` a
                     screen reader speaks it with the page's phonetics (WCAG 3.1.2). `hreflang` is a
                     statement about the far end of the link and does not replace it. --}}
                @php($lang = \Pushery\LegalConsent\Support\ContentLanguage::differingFrom($item['locale'] ?? null))
                <li>
                    @if (($item['url'] ?? null) !== null)
                        <a href="{{ $item['url'] }}"@if ($lang !== null) hreflang="{{ $lang }}" lang="{{ $lang }}"@endif target="_blank" rel="noopener noreferrer">{{ $item['title'] }}</a>
                    @else
                        <span @if ($lang !== null) lang="{{ $lang }}"@endif>{{ $item['title'] }}</span>
                    @endif
                    (v{{ $item['version'] }}) @if (($item['outstanding'] ?? false)) <strong class="legal-consent-action-required">{{ __('legal-consent::ui.action_required') }}</strong> @endif
                </li>
            @empty
                <li class="legal-consent-empty">{{ __('legal-consent::ui.acknowledgements_empty') }}</li>
            @endforelse
        </ul>
    </section>

    <section aria-labelledby="lc-consents">
        <h3 id="lc-consents">{{ __('legal-consent::ui.consents_heading') }}</h3>
        <ul>
            @forelse ($consents as $item)
                {{-- The title's own language, declared only where it differs from the page: a
                     document published only in the default locale still binds, and a RETIRED row is
                     deliberately shown in the language the subject read it in. Without `lang` a
                     screen reader speaks it with the page's phonetics (WCAG 3.1.2). `hreflang` is a
                     statement about the far end of the link and does not replace it. --}}
                @php($lang = \Pushery\LegalConsent\Support\ContentLanguage::differingFrom($item['locale'] ?? null))
                <li>
                    @if (($item['url'] ?? null) !== null)
                        <a href="{{ $item['url'] }}"@if ($lang !== null) hreflang="{{ $lang }}" lang="{{ $lang }}"@endif target="_blank" rel="noopener noreferrer">{{ $item['title'] }}</a>
                    @else
                        <span @if ($lang !== null) lang="{{ $lang }}"@endif>{{ $item['title'] }}</span>
                    @endif
                    @if ($item['held'] && $item['withdrawable'])
                        <button type="button" aria-label="{{ __('legal-consent::ui.withdraw_for', ['title' => $item['title']]) }}" wire:click="withdraw(@js($item['key']))" wire:loading.attr="aria-busy" wire:target="withdraw">
                            {{ __('legal-consent::ui.withdraw') }}
                        </button>
                    {{-- A document retired out from under a holding: `is_active = false` does not
                         end the consents recorded against it, and the row keeps its withdrawal
                         control. It takes the Give button's place because there is nothing left to
                         give — the version it names is no longer published. --}}
                    @elseif ($item['retired'] ?? false)
                        <span class="legal-consent-retired">{{ __('legal-consent::ui.retired') }}</span>
                    {{-- The double opt-in's middle state, and it takes the place of the Give
                         button rather than sitting beside it. Offering to give again would write a
                         second request, which supersedes the first — and stops the confirmation
                         link already in the subject's inbox from working. --}}
                    @elseif ($item['pending_confirmation'] ?? false)
                        <span class="legal-consent-pending">{{ __('legal-consent::ui.confirmation_pending') }}</span>
                    {{-- The counterpart, and only where the embedding screen asked for it. A
                         per-item label again, for the same reason the withdraw button has one:
                         a screen reader's button list of five identical "Give" entries names
                         nothing. --}}
                    @elseif (! $item['held'] && $this->allowGrant)
                        <button type="button" aria-label="{{ __('legal-consent::ui.grant_for', ['title' => $item['title']]) }}" wire:click="grant(@js($item['key']))" wire:loading.attr="aria-busy" wire:target="grant">
                            {{ __('legal-consent::ui.grant') }}
                        </button>
                    @endif
                </li>
            @empty
                <li class="legal-consent-empty">{{ __('legal-consent::ui.consents_empty') }}</li>
            @endforelse
        </ul>
    </section>
    @endif
</div>
