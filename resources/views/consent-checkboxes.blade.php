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

    $bind (optional): the name of a Livewire property holding one entry per FIELD. Given, each
    control binds to `{$bind}.{$field}` and the `old()` restore below is dropped — a `checked`
    next to `wire:model` fights the bound value on every re-render, which is the whole of the
    report this seam answers. Omitted (the default), nothing about this template changes.

      - Keyed by FIELD, not by key. `field` is what the id, the name, the error bag and
        RegistrationRules already use, and the one control that is NOT a document — the Art. 8
        age attestation — is named by its bare key. Keying by `key` would hand that control a
        property no rule validates, which is the bug the `field` fallback above exists for.
      - The Planet49 invariant holds differently, not less: under a binding this template
        contributes NO tick at all, so the only thing that can pre-tick a box is the host's own
        initial value — and it has to be false. Nothing here can enforce that, and pretending
        otherwise would be the more dangerous of the two statements.
      - The accept-time hash below is inert under a binding: Livewire submits no form, so a
        hidden input is never sent. A bound host carries the fingerprint in its own state and
        hands it to the recorder itself.

    `field` is the input name, and it comes from the item rather than from this template. A
    checklist item's `->toArray()` carries it, and RegistrationRules validates exactly that name.
    Building it here as `legal_{key}` was wrong for the one control that is NOT a document: the
    Art. 8 age attestation is named by its key alone, so a hand-built name rendered a required
    box the visitor could tick and never satisfy. The fallback below keeps the minimal shape above
    working — that shape lists documents only, and a document IS `legal_{key}`.
--}}
{{-- ⚠️ BLOCK form on purpose. The inline one-line form strips its expression with
     `trim('()')`, which removes EVERY leading and trailing parenthesis rather than the one pair
     it wrapped — so an expression that itself opens with a bracket loses that bracket and the
     view dies with `unexpected token "@"` pointing at the line below. Measured on this line.

     And the first attempt to say so here broke the view a second way: the raw-block scanner runs
     before comments are stripped and is non-greedy from the FIRST match, so spelling the inline
     directive out inside this comment opened a block that closed at the real `@endphp` and
     swallowed everything between. Describe it, do not spell it. --}}
@php
    $bindTo = ($bind ?? '') !== '' ? $bind : null;
@endphp
@foreach ($documents as $document)
    @php
        $field = $document['field'] ?? 'legal_'.$document['key'];

        // The bag `ShareErrorsFromSession` puts on every web request. Read defensively: a view
        // rendered outside a request has no `$errors` at all, and this stub is renderable anywhere.
        $errorMessage = ($errors ?? null)?->first($field);
        $hasError = ($errorMessage ?? '') !== '';

        // The document NAME inside the sentence becomes the link where it appears there; the
        // separate link below is the fallback for a wording the title is not part of. Resolved
        // HERE rather than at the markup, because `aria-describedby` has to know which of the two
        // shapes this field takes — see below.
        $wordingLink = ($document['url'] ?? null) !== null
            ? \Pushery\LegalConsent\Support\ConsentWordingLink::locate($document['wording'], $document['title'] ?? '')
            : null;

        // ONE `aria-describedby`, assembled here rather than written twice. Two of them on the
        // same element are not two descriptions: the browser keeps the first and drops the rest,
        // so an error target appended next to the document link would silently take the link away.
        // The error comes first — why the field is flagged is said before what it points at.
        //
        // ⚠️ AND THE LINK IS ONLY A DESCRIPTION WHEN IT SITS OUTSIDE THE LABEL. Where the title
        // appears inside the wording, the link is INSIDE the `<label>` and therefore already part
        // of the accessible NAME — measured: name "Ich akzeptiere die Allgemeinen
        // Geschäftsbedingungen.", description "Allgemeine Geschäftsbedingungen", a substring of the
        // name. A screen reader then says the document title twice in a row, on every checkbox of
        // a registration page. The fallback branch below renders the link as a sibling, and there
        // it is a real description; the WireKit twin has always had it that way.
        $describedBy = trim(
            ($hasError ? $field.'_error' : '')
            .' '
            .(($document['url'] ?? null) !== null && $wordingLink === null ? $field.'_link' : '')
        );
    @endphp
    {{-- ⚠️ THIS STUB SHIPS NO CSS, AND THIS IS THE SCREEN WHERE THAT COSTS THE MOST. Every class
         here is a BEM hook with no declarations behind it, so whether these controls are usable on
         a phone is the host's decision — and the box below is a NATIVE checkbox. Measured in a real
         browser with no CSS applied: the input is 13 px and the `<label>` wrapping it is 18 px.
         BOTH are under the 24x24 CSS-pixel minimum (WCAG 2.5.8 AA), on the one screen a visitor
         cannot get past without hitting it.

         ⚠️ SO THE LABEL DOES NOT RESCUE THIS ON ITS OWN, and an earlier draft of this note assumed
         it did. The standard's "enclosed" exception is about the label's box — and by default that
         box is 18 px, not 24. The padding below is required, not merely convenient.

         When you skin these fields: give the interactive control a >= 24px hit target — the
         easiest route is padding on the `<label>`, which already wraps the input and so extends
         the target rather than adding a second one — and any text input a font-size >= 16px,
         because iOS zooms the page on focus below that and a zoomed registration form loses the
         submit button off-screen.

         ⚠️ THE SAME PARAGRAPH LIVED ONLY IN THE ADMIN EDITOR STUB, which has one textarea and two
         buttons and is seen by an operator on a desk. Here there is one touch target per document,
         it is the first view most consumers publish, and it had no guidance at all. --}}
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
                     session, so a view rendered outside a web request is unaffected.

                     Dropped entirely when `$bind` names a Livewire property: there `old()` is
                     empty across a commit anyway, and the attribute would re-assert a stale
                     value against the bound one on every render. --}}
                @if ($bindTo === null)@checked(old($field))@else wire:model="{{ $bindTo }}.{{ $field }}"@endif
                @if ($document['required']) required @endif
                @if ($hasError) aria-invalid="true" @endif
                @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            >
            {{-- The document NAME inside the sentence is the link, rather than a second link
                 repeating the name underneath. `ConsentWordingLink` finds the title inside the
                 SNAPSHOTTED wording and hands back the three pieces; the sentence itself is never
                 rewritten, because it is what the ledger records as the thing agreed to.
                 Null when the title does not appear in the sentence at all (`die AGB` against
                 `Allgemeine Geschäftsbedingungen`) — then the separate link below stays, because a
                 text that cannot be reached breaks the clickwrap requirement (§ 305 Abs. 2 BGB). --}}
            {{-- ⚠️ `lang` ON THE SPAN, NOT ONLY `hreflang` ON THE LINK. `hreflang` names the
                 language at the far end of the link; assistive technology does not switch its
                 voice on it. The WORDING is the passage a screen reader has to pronounce, and it
                 is the sentence the entire consent rests on — spoken with the page's phonetics it
                 is not something a subject can be said to have understood (Art. 7(1), WCAG 3.1.2).
                 Set only when it differs from the page, and omitted rather than emptied when the
                 item carries no locale: `lang=""` is itself a claim. --}}
            @php($lang = \Pushery\LegalConsent\Support\ContentLanguage::differingFrom($document['locale'] ?? null))
            <span @if ($lang !== null) lang="{{ $lang }}"@endif>@if ($wordingLink !== null){{ $wordingLink->before }}<a id="{{ $field }}_link" href="{{ $document['url'] }}"@if (($document['locale'] ?? '') !== '') hreflang="{{ $document['locale'] }}"@endif target="_blank" rel="noopener">{{ $wordingLink->match }}</a>{{ $wordingLink->after }}@else{{ $document['wording'] }}@endif</span>
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

        @if (($document['url'] ?? null) !== null && $wordingLink === null)
            {{-- `hreflang` names the language of the text at the other end, which is not always
                 the language of this page: a mandatory document published only in the default
                 locale still binds, so it appears in its own language (see
                 RegistrationChecklistItem). Without the attribute a screen reader announces the
                 title in the page's language and a translation service treats it as such
                 (WCAG 3.1.2, Language of Parts). Omitted rather than emptied when the item
                 carries no locale — `hreflang=""` is itself a claim. --}}
            <a id="{{ $field }}_link" href="{{ $document['url'] }}"
               @if (($document['locale'] ?? '') !== '') hreflang="{{ $document['locale'] }}" @endif
               @if ($lang !== null) lang="{{ $lang }}" @endif
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
