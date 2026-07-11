{{--
    Publishable, framework-agnostic settings stub. The three legally distinct blocks are
    kept SEPARATE on purpose (the whole point of the package):

      1. Verträge (contracts)          — read-only; you end them by cancelling the account,
                                         not by "withdrawing" (Art. 6(1)(b)).
      2. Zur Kenntnis genommen         — read-only acknowledgements (Art. 13).
      3. Einwilligungen (consents)     — each withdrawable in one click, as easily as it was
                                         given (Art. 7(3)).

    $contracts / $acknowledgements / $consents: lists of ['key','title','version'].
--}}
<section class="legal-consent-settings">
    <h2>{{ __('legal-consent::ui.settings_heading') }}</h2>

    <section aria-labelledby="lc-contracts">
        <h3 id="lc-contracts">{{ __('legal-consent::ui.contracts_heading') }}</h3>
        <ul>
            @foreach ($contracts as $contract)
                <li>{{ $contract['title'] }} (v{{ $contract['version'] }})</li>
            @endforeach
        </ul>
    </section>

    <section aria-labelledby="lc-acknowledgements">
        <h3 id="lc-acknowledgements">{{ __('legal-consent::ui.acknowledgements_heading') }}</h3>
        <ul>
            @foreach ($acknowledgements as $acknowledgement)
                <li>{{ $acknowledgement['title'] }} (v{{ $acknowledgement['version'] }})</li>
            @endforeach
        </ul>
    </section>

    <section aria-labelledby="lc-consents">
        <h3 id="lc-consents">{{ __('legal-consent::ui.consents_heading') }}</h3>
        <ul>
            @foreach ($consents as $consent)
                <li>
                    <span>{{ $consent['title'] }}</span>
                    {{-- The withdraw control is exactly as reachable as the grant was. --}}
                    <form method="post" action="{{ $consent['withdraw_url'] ?? '#' }}">
                        @csrf
                        <input type="hidden" name="document_key" value="{{ $consent['key'] }}">
                        <button type="submit">{{ __('legal-consent::ui.withdraw') }}</button>
                    </form>
                </li>
            @endforeach
        </ul>
    </section>
</section>
