{{--
    WireKit-native variant of the registration consent checkboxes. Publish with
    `--tag=legal-consent-wirekit`. Built from real `x-wirekit::*` components; needs
    `pushery/wirekit` in the host app.

    The legal invariants are in the markup, not in the styling — do not "simplify" them away:
    - never pre-checked: a pre-ticked box is not consent (CJEU C-673/17 Planet49),
    - a real consent is never `required`: coupling it to the service is prohibited (Art. 7(4)),
    - the full text stays linked and retrievable before agreeing (§ 305 Abs. 2 BGB). Configure
      `legal-consent.document_url` and every item carries its `url`; without it there is no link
      to render, and the description reference is dropped with it rather than left dangling.

    $documents: list{key, title, wording, url, required} — `url` may be null.
    A checklist item's ->toArray() adds 'field', 'contentHash' and 'hashField' on top of that.

    `field` is the input name and comes from the item, never from this template — see the plain
    stub for why building it as `legal_{key}` broke the age attestation.
--}}
<x-wirekit::stack gap="md" class="legal-consent-fields">
    @foreach ($documents as $document)
        @php($field = $document['field'] ?? 'legal_'.$document['key'])
        <x-wirekit::stack gap="xs" class="legal-consent-field">
            {{-- The wording is the SNAPSHOTTED consent text — it is what gets recorded in the
                 ledger as what the subject agreed to, so it renders verbatim as the label. --}}
            <x-wirekit::checkbox
                :name="$field"
                :id="$field"
                value="1"
                :label="$document['wording']"
                :required="$document['required']"
                :aria-describedby="($document['url'] ?? null) !== null ? $field.'_link' : null"
                {{-- RESTORED from the visitor's own previous submit, never preset by us — see the
                     plain stub for why that distinction is the whole of Planet49 (C-673/17).
                     Passed as null rather than false when there is nothing to restore, so the
                     attribute is dropped instead of rendering as a value. --}}
                :checked="old($field) ? true : null"
            />

            @if (($document['url'] ?? null) !== null)
                {{-- `hreflang` names the language of the linked text, which is not always this
                     page's — a mandatory document published only in the default locale still
                     binds and appears in its own language. See the plain stub. --}}
                <x-wirekit::link
                    :id="$field.'_link'"
                    :href="$document['url']"
                    :hreflang="($document['locale'] ?? '') !== '' ? $document['locale'] : null"
                    external
                >{{ $document['title'] }}</x-wirekit::link>
            @endif

            @if (($document['hashField'] ?? '') !== '' && ($document['contentHash'] ?? '') !== '')
                {{-- OPT-IN accept-time guard, identical to the plain stub: the render-time
                     fingerprint travels with the form, so a version published between page load
                     and submit is caught (a 409) instead of being frozen unseen. It was missing
                     here while the plain stub had it, which made the themed variant quietly the
                     less protected of the two — the opposite of what publishing a theme means. --}}
                <input type="hidden" name="{{ $document['hashField'] }}" value="{{ $document['contentHash'] }}">
            @endif
        </x-wirekit::stack>
    @endforeach
</x-wirekit::stack>
