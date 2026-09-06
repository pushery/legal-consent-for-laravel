{{--
    Publishable, framework-agnostic settings stub. The three legally distinct blocks are
    kept SEPARATE on purpose (the whole point of the package):

      1. Verträge (contracts)          — read-only; you end them by canceling the account,
                                         not by "withdrawing" (Art. 6(1)(b)).
      2. Zur Kenntnis genommen         — read-only acknowledgments (Art. 13).
      3. Einwilligungen (consents)     — each withdrawable in one click, as easily as it was
                                         given (Art. 7(3)).

    $contracts / $acknowledgements / $consents: lists of
    ['key','title','version','held','outstanding','withdrawable','url','withdraw_url',
    'pending_confirmation'].

    `outstanding` is true when a NEW MAJOR is waiting — the one state that asks the reader to act.
    It is always false for a consent: demanding a voluntary one would be Art. 7(4).

    `url` is null unless the host configured `legal-consent.document_url`. Where it is set the
    title becomes a link, because a screen on which the document being withdrawn cannot be read is
    silent exactly where Art. 7(3) assumes the subject knows what they are deciding about.

    `withdraw_url` is where the form posts, and the PACKAGE fills it now — from the bundled
    session route, which you switch on with `legal-consent.routes.web`. It is null on every entry
    that is not a withdrawable consent the subject currently holds, and null everywhere while that
    route is off; the form is then not rendered at all. Until 0.16.1 this key had no producer
    anywhere in the package and the action fell back to `#`, so the button looked like a working
    control and did nothing — under a comment promising Art. 7(3).

    Pointing it at your own route still works: publish this stub and set the key yourself.
--}}
<section class="legal-consent-settings">
    <h2>{{ __('legal-consent::ui.settings_heading') }}</h2>

    {{-- The result of the redirect the bundled withdrawal route answers with.

         ⚠️ ON THIS PATH THE LIVE REGION ANNOUNCES NOTHING, and the comment here used to claim the
         opposite. Withdrawal is POST -> Redirect -> GET, so the message arrives in a FRESH
         document together with the region that carries it — and a live region present at page
         load never announces, because there is no mutation for it to report. The "always in the
         DOM" reasoning is true of the Livewire twins, where the component morphs the region in
         place, and it does not transfer here. The banner stub already says this correctly about
         itself; these two lines were the copy that did not.

         What the roles still buy is the reading order: the message sits directly after the
         heading, and `status` / `alert` name it when the subject reaches or navigates to it. That
         is what a JS-free stub can promise; moving focus would need a script this stub has by
         design. Both are rendered even when empty rather than wrapped in an `@if`, which costs an
         empty element and keeps this stub a single unconditional shape. --}}
    <p role="status" aria-live="polite">{{ session('legal-consent.status') }}</p>
    <p role="alert">{{ session('legal-consent.error') }}</p>

    {{-- The all-empty case gets ONE sentence instead of three headings over nothing. It is not the
         edge case it looks like: the groups come from the `legal_documents` table, which is empty
         until `legal-consent:publish` runs — so this is what every consumer sees between
         `composer require` and their first publish, and three bare headings read as broken. --}}
    @if (count($contracts) === 0 && count($acknowledgements) === 0 && count($consents) === 0)
        <p class="legal-consent-empty">{{ __('legal-consent::ui.nothing_published') }}</p>
    @else

    <section aria-labelledby="lc-contracts">
        <h3 id="lc-contracts">{{ __('legal-consent::ui.contracts_heading') }}</h3>
        <ul>
            @forelse ($contracts as $contract)
                {{-- The title can be in a language this page is not in: a mandatory document
                     published only in the default locale still binds, and a RETIRED row is shown in
                     the language the subject read it in — deliberately, see RetiredHoldings. Without
                     `lang` a screen reader speaks it with the page's phonetics (WCAG 3.1.2).
                     `hreflang` says the same thing about the far end of the link and does not
                     replace it: assistive technology does not switch its voice on `hreflang`.
                     Both omitted rather than emptied when the entry carries no locale. --}}
                @php($lang = \Pushery\LegalConsent\Support\ContentLanguage::differingFrom($contract['locale'] ?? null))
                <li>
                    @if (($contract['url'] ?? null) !== null)
                        <a href="{{ $contract['url'] }}"@if ($lang !== null) hreflang="{{ $lang }}" lang="{{ $lang }}"@endif target="_blank" rel="noopener noreferrer">{{ $contract['title'] }}</a>
                    @else
                        <span @if ($lang !== null) lang="{{ $lang }}"@endif>{{ $contract['title'] }}</span>
                    @endif
                    (v{{ $contract['version'] }}) @if (($contract['outstanding'] ?? false)) <strong>{{ __('legal-consent::ui.action_required') }}</strong> @endif</li>
            @empty
                <li class="legal-consent-empty">{{ __('legal-consent::ui.contracts_empty') }}</li>
            @endforelse
        </ul>
    </section>

    <section aria-labelledby="lc-acknowledgements">
        <h3 id="lc-acknowledgements">{{ __('legal-consent::ui.acknowledgements_heading') }}</h3>
        <ul>
            @forelse ($acknowledgements as $acknowledgement)
                {{-- The title can be in a language this page is not in: a mandatory document
                     published only in the default locale still binds, and a RETIRED row is shown in
                     the language the subject read it in — deliberately, see RetiredHoldings. Without
                     `lang` a screen reader speaks it with the page's phonetics (WCAG 3.1.2).
                     `hreflang` says the same thing about the far end of the link and does not
                     replace it: assistive technology does not switch its voice on `hreflang`.
                     Both omitted rather than emptied when the entry carries no locale. --}}
                @php($lang = \Pushery\LegalConsent\Support\ContentLanguage::differingFrom($acknowledgement['locale'] ?? null))
                <li>
                    @if (($acknowledgement['url'] ?? null) !== null)
                        <a href="{{ $acknowledgement['url'] }}"@if ($lang !== null) hreflang="{{ $lang }}" lang="{{ $lang }}"@endif target="_blank" rel="noopener noreferrer">{{ $acknowledgement['title'] }}</a>
                    @else
                        <span @if ($lang !== null) lang="{{ $lang }}"@endif>{{ $acknowledgement['title'] }}</span>
                    @endif
                    (v{{ $acknowledgement['version'] }}) @if (($acknowledgement['outstanding'] ?? false)) <strong>{{ __('legal-consent::ui.action_required') }}</strong> @endif</li>
            @empty
                <li class="legal-consent-empty">{{ __('legal-consent::ui.acknowledgements_empty') }}</li>
            @endforelse
        </ul>
    </section>

    <section aria-labelledby="lc-consents">
        <h3 id="lc-consents">{{ __('legal-consent::ui.consents_heading') }}</h3>
        <ul>
            @forelse ($consents as $consent)
                {{-- The title can be in a language this page is not in: a mandatory document
                     published only in the default locale still binds, and a RETIRED row is shown in
                     the language the subject read it in — deliberately, see RetiredHoldings. Without
                     `lang` a screen reader speaks it with the page's phonetics (WCAG 3.1.2).
                     `hreflang` says the same thing about the far end of the link and does not
                     replace it: assistive technology does not switch its voice on `hreflang`.
                     Both omitted rather than emptied when the entry carries no locale. --}}
                @php($lang = \Pushery\LegalConsent\Support\ContentLanguage::differingFrom($consent['locale'] ?? null))
                <li>
                    @if (($consent['url'] ?? null) !== null)
                        <a href="{{ $consent['url'] }}"@if ($lang !== null) hreflang="{{ $lang }}" lang="{{ $lang }}"@endif target="_blank" rel="noopener noreferrer">{{ $consent['title'] }}</a>
                    @else
                        <span @if ($lang !== null) lang="{{ $lang }}"@endif>{{ $consent['title'] }}</span>
                    @endif
                    {{-- The double opt-in's middle state: entered but not yet confirmed reads as
                         never entered otherwise, and the screen would say nothing at all. --}}
                    @if ($consent['pending_confirmation'] ?? false)
                        <span class="legal-consent-pending">{{ __('legal-consent::ui.confirmation_pending') }}</span>
                    @endif

                    {{-- A document retired out from under a holding. `is_active = false` does not
                         end the consents already recorded against it, so the row stays here with
                         its withdrawal control intact — but it is never `outstanding`, and without
                         this label the subject cannot see why the version is older than the one
                         they last read about. --}}
                    @if ($consent['retired'] ?? false)
                        <span class="legal-consent-retired">{{ __('legal-consent::ui.retired') }}</span>
                    @endif

                    {{-- The withdraw control is exactly as reachable as the grant was — and it is
                         rendered only when there is somewhere for it to go. A form with no action
                         is not a control, it is the appearance of one. --}}
                    @if (($consent['withdraw_url'] ?? null) !== null)
                        <form method="post" action="{{ $consent['withdraw_url'] }}">
                            @csrf
                            <input type="hidden" name="document_key" value="{{ $consent['key'] }}">
                            <button type="submit" aria-label="{{ __('legal-consent::ui.withdraw_for', ['title' => $consent['title']]) }}">{{ __('legal-consent::ui.withdraw') }}</button>
                        </form>
                    @endif
                </li>
            @empty
                <li class="legal-consent-empty">{{ __('legal-consent::ui.consents_empty') }}</li>
            @endforelse
        </ul>
    </section>
    @endif
</section>
