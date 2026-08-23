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
    A checklist item's ->toArray() adds 'field', 'contentHash' and 'hashField' on top of that.

    `field` is the input name, and it comes from the item rather than from this template. A
    checklist item's `->toArray()` carries it, and RegistrationRules validates exactly that name.
    Building it here as `legal_{key}` was wrong for the one control that is NOT a document: the
    Art. 8 age attestation is named by its key alone, so a hand-built name rendered a required
    box the visitor could tick and never satisfy. The fallback below keeps the minimal shape above
    working — that shape lists documents only, and a document IS `legal_{key}`.
--}}
@foreach ($documents as $document)
    @php($field = $document['field'] ?? 'legal_'.$document['key'])
    <div class="legal-consent-field">
        <label for="{{ $field }}">
            <input
                type="checkbox"
                id="{{ $field }}"
                name="{{ $field }}"
                value="1"
                @checked(false)
                @if ($document['required']) required @endif
                @if (($document['url'] ?? null) !== null) aria-describedby="{{ $field }}_link" @endif
            >
            <span>{{ $document['wording'] }}</span>
        </label>

        @if (($document['url'] ?? null) !== null)
            <a id="{{ $field }}_link" href="{{ $document['url'] }}" target="_blank" rel="noopener noreferrer">
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
