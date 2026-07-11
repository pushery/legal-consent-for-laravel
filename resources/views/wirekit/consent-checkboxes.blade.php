{{--
    WireKit-flavored variant of the registration consent checkboxes. Publish with
    `--tag=legal-consent-wirekit` and adapt to your WireKit form controls. WireKit spacing
    tokens; never pre-checked (Planet49); a consent is never `required` (Art. 7(4)); the full
    text stays linked and retrievable (§ 305 II BGB).

    $documents: ['key','title','wording','url','required'].
--}}
@foreach ($documents as $document)
    <div class="wk-field wk-row wk-gap-sm wk-items-start legal-consent-field">
        {{-- Swap for <wk:checkbox name="legal_{{ $document['key'] }}" /> in a WireKit app. --}}
        <label class="wk-checkbox-label" for="legal_{{ $document['key'] }}">
            <input
                class="wk-checkbox"
                type="checkbox"
                id="legal_{{ $document['key'] }}"
                name="legal_{{ $document['key'] }}"
                value="1"
                @checked(false)
                @if ($document['required']) required @endif
                aria-describedby="legal_{{ $document['key'] }}_link"
            >
            <span>{{ $document['wording'] }}</span>
        </label>

        <a class="wk-link" id="legal_{{ $document['key'] }}_link" href="{{ $document['url'] }}" target="_blank" rel="noopener noreferrer">
            {{ $document['title'] }}
        </a>
    </div>
@endforeach
