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

    A rejected required box renders its message below the label, and the input points at it. The
    WireKit twin carries no such block on purpose: `x-wirekit::checkbox` reads Laravel's error bag
    itself and merges its own error target into the `aria-describedby` it is handed, so the same
    result arrives through the component. This stub has no component to do that for it.

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
    @php
        $field = $document['field'] ?? 'legal_'.$document['key'];

        // The bag `ShareErrorsFromSession` puts on every web request. Read defensively: a view
        // rendered outside a request has no `$errors` at all, and this stub is renderable anywhere.
        $errorMessage = ($errors ?? null)?->first($field);
        $hasError = ($errorMessage ?? '') !== '';

        // ONE `aria-describedby`, assembled here rather than written twice. Two of them on the
        // same element are not two descriptions: the browser keeps the first and drops the rest,
        // so an error target appended next to the document link would silently take the link away.
        // The error comes first — why the field is flagged is said before what it points at.
        $describedBy = trim(
            ($hasError ? $field.'_error' : '')
            .' '
            .(($document['url'] ?? null) !== null ? $field.'_link' : '')
        );
    @endphp
    <div class="legal-consent-field">
        <label for="{{ $field }}">
            <input
                type="checkbox"
                id="{{ $field }}"
                name="{{ $field }}"
                value="1"
                {{-- RESTORED from the visitor's own previous submit, never preset by us. That
                     distinction is the whole of Planet49 (C-673/17): what is forbidden is a box
                     the provider ticked in advance. `old()` holds only what this visitor sent a
                     moment ago, and an unticked box is not submitted at all — so it carries no
                     key and comes back empty. Without this, one mistyped e-mail wipes every
                     consent already given and the visitor re-ticks the same boxes, which is how a
                     consent screen stops being read. `old()` returns the default when there is no
                     session, so a view rendered outside a web request is unaffected. --}}
                @checked(old($field))
                @if ($document['required']) required @endif
                @if ($hasError) aria-invalid="true" @endif
                @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            >
            <span>{{ $document['wording'] }}</span>
        </label>

        @if ($hasError)
            {{-- The message the server rejected the submit with. `RegistrationRules::messages()`
                 produces it and the package ships all three in seven locales; without a block to
                 render it none of them can reach a visitor, because an unticked checkbox is not
                 submitted at all, so `old()` restores nothing and the page after a refusal is
                 identical to a fresh one (WCAG 3.3.1, 3.3.3).

                 What carries the announcement is the pairing above — `aria-invalid` plus the
                 reference — so the message is read out WITH the control rather than sitting
                 somewhere on the page. `role="alert"` covers the other direction: a host that
                 re-renders this field without a full page load gets the failure spoken. It does
                 nothing on a plain redirect-back, where the region is already in the document when
                 it loads, which is why the association is the half that has to be right. --}}
            <p id="{{ $field }}_error" class="legal-consent-error" role="alert">{{ $errorMessage }}</p>
        @endif

        @if (($document['url'] ?? null) !== null)
            {{-- `hreflang` names the language of the text at the other end, which is not always
                 the language of this page: a mandatory document published only in the default
                 locale still binds, so it appears in its own language (see
                 RegistrationChecklistItem). Without the attribute a screen reader announces the
                 title in the page's language and a translation service treats it as such
                 (WCAG 3.1.2, Language of Parts). Omitted rather than emptied when the item
                 carries no locale — `hreflang=""` is itself a claim. --}}
            <a id="{{ $field }}_link" href="{{ $document['url'] }}"
               @if (($document['locale'] ?? '') !== '') hreflang="{{ $document['locale'] }}" @endif
               target="_blank" rel="noopener noreferrer">
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
