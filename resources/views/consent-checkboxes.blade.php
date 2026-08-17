{{--
    Publishable, framework-agnostic stub — customize freely (no Livewire/Flux dependency).
    Renders one checkbox per legal document for a registration/consent form.

    Dark-pattern invariants (do NOT remove):
      - Never pre-checked (Planet49 C-673/17): @checked is hard-coded false.
      - A real consent is NEVER `required` (Kopplungsverbot Art. 7(4)); the caller must
        pass required=false for consent-type documents.
      - The full text is linked and actually retrievable (clickwrap, § 305 II BGB). Configure
        `legal-consent.document_url` and every item carries its `url`; without it there is no
        link to render, and the description reference is dropped with it rather than left
        dangling at an element that is not there.

    $documents: list of ['key', 'title', 'wording', 'url', 'required'] — `url` may be null.
--}}
@foreach ($documents as $document)
    <div class="legal-consent-field">
        <label for="legal_{{ $document['key'] }}">
            <input
                type="checkbox"
                id="legal_{{ $document['key'] }}"
                name="legal_{{ $document['key'] }}"
                value="1"
                @checked(false)
                @if ($document['required']) required @endif
                @if (($document['url'] ?? null) !== null) aria-describedby="legal_{{ $document['key'] }}_link" @endif
            >
            <span>{{ $document['wording'] }}</span>
        </label>

        @if (($document['url'] ?? null) !== null)
            <a id="legal_{{ $document['key'] }}_link" href="{{ $document['url'] }}" target="_blank" rel="noopener noreferrer">
                {{ $document['title'] }}
            </a>
        @endif

        @if (($document['hashField'] ?? '') !== '' && ($document['contentHash'] ?? '') !== '')
            {{-- OPT-IN accept-time guard: carries the render-time fingerprint so a version released
                 between page load and submit is caught (a 409) instead of silently frozen. Enable it
                 by including 'contentHash' + 'hashField' from a checklist item's ->toArray(); omit
                 them (the documented $documents shape) to keep the prior no-guard behavior. --}}
            <input type="hidden" name="{{ $document['hashField'] }}" value="{{ $document['contentHash'] }}">
        @endif
    </div>
@endforeach
