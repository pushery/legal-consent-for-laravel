{{--
    Publishable, framework-agnostic settings stub. The three legally distinct blocks are
    kept SEPARATE on purpose (the whole point of the package):

      1. Verträge (contracts)          — read-only; you end them by canceling the account,
                                         not by "withdrawing" (Art. 6(1)(b)).
      2. Zur Kenntnis genommen         — read-only acknowledgements (Art. 13).
      3. Einwilligungen (consents)     — each withdrawable in one click, as easily as it was
                                         given (Art. 7(3)).

    $contracts / $acknowledgements / $consents: lists of
    ['key','title','version','held','outstanding','withdrawable','url'].

    `outstanding` is true when a NEW MAJOR is waiting — the one state that asks the reader to act.
    It is always false for a consent: demanding a voluntary one would be Art. 7(4).

    `url` is null unless the host configured `legal-consent.document_url`. Where it is set the
    title becomes a link, because a screen on which the document being withdrawn cannot be read is
    silent exactly where Art. 7(3) assumes the subject knows what they are deciding about.

    ⚠️ `withdraw_url` below is NOT one of those keys and the package does not provide it. This stub
    is framework-agnostic, so it cannot post to a Livewire action, and the bundled JSON endpoint is
    off by default and answers 204 rather than redirecting. Point the form at your own route —
    as shipped the button submits to `#` and does nothing.
--}}
<section class="legal-consent-settings">
    <h2>{{ __('legal-consent::ui.settings_heading') }}</h2>

    <section aria-labelledby="lc-contracts">
        <h3 id="lc-contracts">{{ __('legal-consent::ui.contracts_heading') }}</h3>
        <ul>
            @foreach ($contracts as $contract)
                <li>
                    @if (($contract['url'] ?? null) !== null)
                        <a href="{{ $contract['url'] }}" target="_blank" rel="noopener noreferrer">{{ $contract['title'] }}</a>
                    @else
                        {{ $contract['title'] }}
                    @endif
                    (v{{ $contract['version'] }}) @if (($contract['outstanding'] ?? false)) <strong>{{ __('legal-consent::ui.action_required') }}</strong> @endif</li>
            @endforeach
        </ul>
    </section>

    <section aria-labelledby="lc-acknowledgements">
        <h3 id="lc-acknowledgements">{{ __('legal-consent::ui.acknowledgements_heading') }}</h3>
        <ul>
            @foreach ($acknowledgements as $acknowledgement)
                <li>
                    @if (($acknowledgement['url'] ?? null) !== null)
                        <a href="{{ $acknowledgement['url'] }}" target="_blank" rel="noopener noreferrer">{{ $acknowledgement['title'] }}</a>
                    @else
                        {{ $acknowledgement['title'] }}
                    @endif
                    (v{{ $acknowledgement['version'] }}) @if (($acknowledgement['outstanding'] ?? false)) <strong>{{ __('legal-consent::ui.action_required') }}</strong> @endif</li>
            @endforeach
        </ul>
    </section>

    <section aria-labelledby="lc-consents">
        <h3 id="lc-consents">{{ __('legal-consent::ui.consents_heading') }}</h3>
        <ul>
            @foreach ($consents as $consent)
                <li>
                    @if (($consent['url'] ?? null) !== null)
                        <a href="{{ $consent['url'] }}" target="_blank" rel="noopener noreferrer">{{ $consent['title'] }}</a>
                    @else
                        <span>{{ $consent['title'] }}</span>
                    @endif
                    {{-- The withdraw control is exactly as reachable as the grant was. --}}
                    <form method="post" action="{{ $consent['withdraw_url'] ?? '#' }}">
                        @csrf
                        <input type="hidden" name="document_key" value="{{ $consent['key'] }}">
                        <button type="submit" aria-label="{{ __('legal-consent::ui.withdraw_for', ['title' => $consent['title']]) }}">{{ __('legal-consent::ui.withdraw') }}</button>
                    </form>
                </li>
            @endforeach
        </ul>
    </section>
</section>
