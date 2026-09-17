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

    The validation error is deliberately NOT wired here. `x-wirekit::checkbox` resolves it from
    Laravel's error bag by `name`, renders the message, sets `aria-invalid` and merges its own error
    target into the `aria-describedby` passed below — into ONE attribute, because a second one would
    make the browser drop the caller's association. Passing an `:error` prop would set exactly what
    the component already resolves and change nothing. The plain stub renders the block itself; it
    has no component to do this for it.

    $documents: list{key, title, wording, url, required} — `url` may be null.
    A checklist item's ->toArray() adds 'field', 'contentHash' and 'hashField' on top of that.

    $bind (optional): the name of a Livewire property holding one entry per FIELD. Given, each
    control binds to `{$bind}.{$field}` and the `old()` restore is dropped. Keyed by field rather
    than by key, the Planet49 invariant moves to the host's initial value, and the accept-time
    hash goes inert — the plain stub carries the full reasoning for all three.

    `field` is the input name and comes from the item, never from this template — see the plain
    stub for why building it as `legal_{key}` broke the age attestation.
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
<x-wirekit::stack gap="md" class="legal-consent-fields">
    @foreach ($documents as $document)
        @php($field = $document['field'] ?? 'legal_'.$document['key'])
        {{-- Resolved to a bag BEFORE the tag, because Blade's component-tag compiler parses the
             attribute list itself and runs no directives inside it — a conditional written there
             would land in the markup as text.

             ⚠️ And it is handed over as `:attributes`, not echoed into the tag. The compiler
             recognizes an echoed bag only when the variable is literally named `$attributes`
             (`parseAttributeBag` matches that name and nothing else); any other name survives
             into the attribute string as garbage and the view dies at the next `@endif`.
             `:attributes` is the form that regex rewrites to, so it is the same thing said
             directly. An empty bag renders nothing. --}}
        @php($binding = new \Illuminate\View\ComponentAttributeBag(
            $bindTo !== null ? ['wire:model' => $bindTo.'.'.$field] : []
        ))
        {{-- Same resolution as the plain stub, and it has to happen here for the same reason: the
             shape this field takes decides whether `aria-describedby` may point at the link. Where
             the title appears INSIDE the wording the link sits in the label and is already part of
             the accessible name, so describing the field by it reads the title twice. --}}
        @php($wordingLink = ($document['url'] ?? null) !== null
            ? \Pushery\LegalConsent\Support\ConsentWordingLink::locate($document['wording'], $document['title'] ?? '')
            : null)
        {{-- `lang` on the FIELD rather than on the label, because the wording reaches the checkbox
             as a component prop and there is nowhere else to hang it — and everything inside this
             wrapper is the document's text anyway: the snapshotted wording and the title link.
             `hreflang` below is a different statement (the language at the far end of the link)
             and does not replace it; assistive technology does not switch its voice on `hreflang`.
             Set only when it differs from the page. See the plain stub, and ContentLanguage. --}}
        @php($lang = \Pushery\LegalConsent\Support\ContentLanguage::differingFrom($document['locale'] ?? null))
        {{-- THE DIALOG, AND IT IS RESOLVED HERE BECAUSE IT DECIDES WHAT THE ANCHOR DOES.
             It holds the FRAGMENT ROUTE's address and fetches on first open — it does not inline the
             text. That is this package's own decision rather than a new one: the fragment route was
             built for exactly this dialog, and the guide says why in the same breath. A registration
             form can name four documents, a privacy notice is tens of kilobytes, and in the normal
             case the reader opens none of them — so inlining all four is a large certain cost for an
             uncommon benefit, on the page whose load time decides whether somebody registers at all.

             No fragment route means no dialog, and the anchor stays exactly what it was: a link to
             the page, which says the same thing honestly. --}}
        @php($dialog = config('legal-consent.ui.wording_dialog', false)
            {{-- ASKED OF THE ROUTER, not of the switch that is supposed to have registered it. The
                 flag says what the configuration wants; only the router knows what is actually
                 registered, and `route()` on a name that is not throws — out of a view, that takes
                 the whole registration form down over a dialog nobody needed. --}}
            && \Illuminate\Support\Facades\Route::has('legal-consent.document.fragment')
            && ($document['url'] ?? null) !== null
            && ($document['locale'] ?? '') !== ''
            ? route('legal-consent.document.fragment', ['key' => $document['key'], 'locale' => $document['locale']])
            : null)
        @php($dialogName = 'legal-consent-'.$field)
        {{-- ASSEMBLED rather than written as a Blade `@if` INSIDE the component tag. That form does
             not compile at all here — the directive lands between attributes and the view dies on
             an unexpected `@endif` — and where it does compile it leaves its own literal spaces
             behind. An empty bag renders nothing, which is the off state.

             `x-bind:aria-haspopup` is BOUND rather than written, and the distinction is the point: a
             screen reader announcing "link" sets up an expectation of navigation, and with scripting
             this opens a dialog instead. Without scripting it really does navigate, so a hard-coded
             `aria-haspopup` would be a promise the page does not keep. Alpine binds it only once it
             is running, which is exactly when it is true.

             A WINDOW EVENT rather than the modal's own trigger slot: that slot renders a div, and
             flow content inside a label is invalid markup. --}}
        @php($dialogTrigger = new \Illuminate\View\ComponentAttributeBag($dialog !== null ? [
            'x-bind:aria-haspopup' => "'dialog'",
            'x-on:click.prevent' => '$dispatch(\'wirekit-modal-show\', { name: \''.$dialogName.'\' })',
        ] : []))
        <x-wirekit::stack gap="xs" class="legal-consent-field" :lang="$lang">
            {{-- The wording is the SNAPSHOTTED consent text — it is what gets recorded in the
                 ledger as what the subject agreed to, so it renders verbatim as the label. --}}
            <x-wirekit::checkbox
                :name="$field"
                :id="$field"
                value="1"
                {{-- The SLOT below always carries the text: the linked sentence, or the wording itself.
                     The component falls back to this prop only for an EMPTY slot, and inside a
                     Livewire render the slot is never empty: Livewire wraps the `@if` in morph markers,
                     so a branch that rendered nothing left the checkbox with no text and no accessible
                     name. The prop stays as the same wording for a render outside Livewire. --}}
                :label="$document['wording']"
                :required="$document['required']"
                :aria-describedby="($document['url'] ?? null) !== null && $wordingLink === null ? $field.'_link' : null"
                {{-- RESTORED from the visitor's own previous submit, never preset by us — see the
                     plain stub for why that distinction is the whole of Planet49 (C-673/17).
                     Passed as null rather than false when there is nothing to restore, so the
                     attribute is dropped instead of rendering as a value. Null under a binding
                     too, for the reason the plain stub spells out: the bound property owns the
                     state, and a `checked` beside it fights every re-render. --}}
                :checked="$bindTo === null && old($field) ? true : null"
                :attributes="$binding"
            >@if ($wordingLink !== null){{ $wordingLink->before }}<x-wirekit::link
                    :id="$field.'_link'"
                    :href="$document['url']"
                    :hreflang="($document['locale'] ?? '') !== '' ? $document['locale'] : null"
                    external
                    :attributes="$dialogTrigger"
                >{{ $wordingLink->match }}</x-wirekit::link>{{ $wordingLink->after }}@else{{ $document['wording'] }}@endif</x-wirekit::checkbox>

            @if (($document['url'] ?? null) !== null && $wordingLink === null)
                {{-- `hreflang` names the language of the linked text, which is not always this
                     page's — a mandatory document published only in the default locale still
                     binds and appears in its own language. See the plain stub. --}}
                <x-wirekit::link
                    :id="$field.'_link'"
                    :href="$document['url']"
                    :hreflang="($document['locale'] ?? '') !== '' ? $document['locale'] : null"
                    external
                    :attributes="$dialogTrigger"
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

        {{-- THE TEXT, IN A DIALOG OVER THE FORM. Outside the field's stack and outside the label:
             a dialog is not part of the checkbox's accessible name, and flow content inside a
             label is invalid markup.

             The body is emitted unescaped because it is the frozen, already-sanitized HTML the
             published page itself renders — one allowlist, applied when the text was stored, and
             the same bytes the ledger records as accepted. Re-escaping here would show the reader
             markup instead of a document.

             It scrolls on its own and says so to a keyboard: a legal text outgrows any window, the
             dialog opens with focus on the close control in the header, and that control sits
             OUTSIDE this box — so without `tabindex` the arrow keys move the dialog and not the
             prose, and the reader is asked to tick that they read a document they could see the
             top of (WCAG 2.1.1). `role="region"` without an accessible name is worse than none, so
             it carries the title. --}}
        @if ($dialog !== null)
            <x-wirekit::modal :name="$dialogName" size="lg">
                <x-wirekit::modal.header>{{ $document['title'] }}</x-wirekit::modal.header>

                <x-wirekit::modal.body class="max-h-[65vh] overflow-y-auto" tabindex="0" role="region" :aria-label="$document['title']">
                    {{-- FETCHED ON FIRST OPEN, and kept afterwards. The text is the published,
                         already-sanitized HTML the legal page itself renders — one allowlist, applied
                         when it was stored, and the same bytes the ledger records as accepted.
                         `x-html` is what puts it in as markup rather than as escaped source.

                         A failed fetch says so instead of leaving an empty box: a dialog that opens
                         onto nothing reads as a document with no content, and the reader is then
                         asked to tick that they read it. The anchor behind this dialog is still a
                         real link, so the sentence points at the way that always works. --}}
                    <div x-data="{ body: '', state: 'idle' }"
                         x-on:wirekit-modal-show.window="if ($event.detail?.name === @js($dialogName) && state === 'idle') {
                             state = 'loading';
                             fetch(@js($dialog), { headers: { 'Accept': 'text/html' } })
                                 .then(r => r.ok ? r.text() : Promise.reject(r.status))
                                 .then(html => { body = html; state = 'ready' })
                                 .catch(() => { state = 'failed' });
                         }"
                         @if ($lang !== null) lang="{{ $lang }}" @endif>
                        <div x-show="state === 'ready'" x-html="body"></div>
                        <p x-show="state === 'loading'" x-cloak>{{ __('legal-consent::ui.dialog_loading') }}</p>
                        <p x-show="state === 'failed'" x-cloak>{{ __('legal-consent::ui.dialog_failed') }}</p>
                    </div>
                </x-wirekit::modal.body>

                <x-wirekit::modal.footer>
                    <x-wirekit::modal.close>
                        <x-wirekit::button size="sm" surface="soft">{{ __('legal-consent::ui.dialog_close') }}</x-wirekit::button>
                    </x-wirekit::modal.close>
                </x-wirekit::modal.footer>
            </x-wirekit::modal>
        @endif
    @endforeach
</x-wirekit::stack>
