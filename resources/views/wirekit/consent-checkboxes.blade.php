{{--
    WireKit-native variant of the registration consent checkboxes. Publish with
    `--tag=legal-consent-wirekit`. Built from real `x-wirekit::*` components; needs
    `pushery/wirekit` in the host app.

    The legal invariants are in the markup, not in the styling — do not "simplify" them away:
    - never pre-checked: a pre-ticked box is not consent (CJEU C-673/17 Planet49),
    - a real consent is never `required`: coupling it to the service is prohibited (Art. 7(4)),
    - the full text stays linked and retrievable before agreeing (§ 305 Abs. 2 BGB).

    $documents: list{key, title, wording, url, required}.
--}}
<x-wirekit::stack gap="md" class="legal-consent-fields">
    @foreach ($documents as $document)
        <x-wirekit::stack gap="xs" class="legal-consent-field">
            {{-- The wording is the SNAPSHOTTED consent text — it is what gets recorded in the
                 ledger as what the subject agreed to, so it renders verbatim as the label. --}}
            <x-wirekit::checkbox
                :name="'legal_'.$document['key']"
                :id="'legal_'.$document['key']"
                value="1"
                :label="$document['wording']"
                :required="$document['required']"
                :aria-describedby="'legal_'.$document['key'].'_link'"
            />

            <x-wirekit::link
                :id="'legal_'.$document['key'].'_link'"
                :href="$document['url']"
                external
            >{{ $document['title'] }}</x-wirekit::link>
        </x-wirekit::stack>
    @endforeach
</x-wirekit::stack>
