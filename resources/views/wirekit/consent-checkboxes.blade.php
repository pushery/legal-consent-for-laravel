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

    // Every dialog this view renders, collected while the fields are built and emitted after the
    // stack closes. The reasoning is at the collector, where the markup used to be.
    $deferredDialogs = [];
@endphp
{{-- ⚠️ A ROOT ELEMENT THAT IS NOT A FLEX CONTAINER, and it is the whole repair rather than
     packaging. The dialogs have to be emitted somewhere with NO `gap`, and inside a published
     stub there is no page root to reach for — so the view brings one. Block layout has no gap, so
     a zero-height dialog wrapper costs nothing here.

     It replaces the stack as this view's single root, so a caller still sees exactly one child
     and the `legal-consent-fields` class stays where a consumer's CSS expects it. --}}
<div class="legal-consent">
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
        @php($members = is_array($document['documents'] ?? null) ? array_values($document['documents']) : [])
        @php($cut = $members !== [] ? \Pushery\LegalConsent\Support\ConsentWordingSegments::for($document['wording'], $members) : null)
        @php($wordingLink = $cut === null && ($document['url'] ?? null) !== null
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
            {{-- `heading=0`: this dialog's header names the document already. --}}
            ? route('legal-consent.document.fragment', ['key' => $document['key'], 'locale' => $document['locale'], 'heading' => 0])
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
        {{-- The label is assembled and echoed ONCE, rather than written as a chain of
             directives. Blade leaves a closing directive that sits flush against the token before
             it in the output as literal text, and this slot may carry no stray whitespace: it is
             the accessible NAME of the control, and a space landing between a title and the comma
             after it is visible in the sentence a consent rests on.

             ⚠️ AND IT IS BUILT WITH THE ONE-LINE FORM, NOT A BLOCK. This file already uses the
             one-line form above, and the raw-block scanner pairs the first of those with the end
             of any block opened later — swallowing everything between and failing far from here
             with a syntax error in a compiled file. Measured: adding a block broke the view at its
             twentieth compiled line, four hundred lines before the block itself.

             The link is RENDERED by its component through a view of its own, never rebuilt here.
             That view says why it cannot be a string literal in this file. --}}
        @php($memberLinkId = static fn (array $member): string => $field.'_link_'.(string) ($member['key'] ?? ''))
        {{-- A DIALOG PER MEMBER of a grouped control, asked the same questions a single document is.
             The grouped sentence used to render its links with an empty bag, so the one control
             that names several documents was the one that sent the reader out of a half-filled form
             to read them, while a document standing alone opened in a dialog. The name is per
             MEMBER: the control's own name would have given every text in the sentence one dialog. --}}
        @php($memberDialog = static fn (array $member): ?string => config('legal-consent.ui.wording_dialog', false) && \Illuminate\Support\Facades\Route::has('legal-consent.document.fragment') && ($member['url'] ?? null) !== null && ($member['locale'] ?? '') !== '' && ($member['key'] ?? '') !== '' ? route('legal-consent.document.fragment', ['key' => $member['key'], 'locale' => $member['locale'], 'heading' => 0]) : null)
        @php($memberDialogName = static fn (array $member): string => $dialogName.'-'.(string) ($member['key'] ?? ''))
        @php($memberTrigger = static fn (array $member): \Illuminate\View\ComponentAttributeBag => new \Illuminate\View\ComponentAttributeBag($memberDialog($member) !== null ? ['x-bind:aria-haspopup' => "'dialog'", 'x-on:click.prevent' => '$dispatch(\'wirekit-modal-show\', { name: \''.$memberDialogName($member).'\' })'] : []))
        @php($renderLink = static fn (array $target, string $text, ?string $id, \Illuminate\View\ComponentAttributeBag $extra): string => trim(view('legal-consent::wirekit.consent-link', ['id' => $id, 'href' => (string) ($target['url'] ?? ''), 'hreflang' => ($target['locale'] ?? '') !== '' ? $target['locale'] : null, 'extra' => $extra, 'text' => $text])->render()))
        @php($singleLabel = $wordingLink !== null ? e($wordingLink->before).$renderLink($document, $wordingLink->match, $field.'_link', $dialogTrigger).e($wordingLink->after) : e($document['wording']))
        @php($label = $cut === null ? $singleLabel : implode('', array_map(static fn (array $segment): string => $segment['document'] !== null && ($segment['document']['url'] ?? null) !== null ? $renderLink($segment['document'], $segment['text'], null, $memberTrigger($segment['document'])) : e($segment['text']), $cut->segments)))
        {{-- ONE attribute carrying every id: the field's own link where it is a sibling, every
             grouped member the sentence does not name, and the host's. Collapsed rather than only
             trimmed — the parts are joined with a separator whether or not they are there, so an
             absent one leaves a double space inside the value. --}}
        @php($describedByIds = (string) preg_replace('/\\s+/', ' ', trim(($cut === null && ($document['url'] ?? null) !== null && $wordingLink === null ? $field.'_link' : '').' '.($cut === null ? '' : implode(' ', array_map(static fn (array $member): string => $memberLinkId($member), array_filter($cut->unlinked, static fn (array $member): bool => ($member['url'] ?? null) !== null)))).' '.trim((string) ($describedBy ?? '')))))
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
                {{-- The host's own id is appended rather than rendered as a second attribute, and
                     the component merges its error target into whatever it is handed. A screen
                     whose validation message belongs to the FORM -- a re-consent screen locks the
                     account, so the reason nothing happened has to be heard on submit -- points
                     every control at one shared line, and a second attribute would be dropped by
                     the browser along with the document link. Last, because a message about all of
                     them is read after this field's own. --}}
                :aria-describedby="$describedByIds ?: null"
                {{-- RESTORED from the visitor's own previous submit, never preset by us — see the
                     plain stub for why that distinction is the whole of Planet49 (C-673/17).
                     Passed as null rather than false when there is nothing to restore, so the
                     attribute is dropped instead of rendering as a value. Null under a binding
                     too, for the reason the plain stub spells out: the bound property owns the
                     state, and a `checked` beside it fights every re-render. --}}
                :checked="$bindTo === null && old($field) ? true : null"
                :attributes="$binding"
            >{!! $label !!}</x-wirekit::checkbox>

            @foreach ($cut?->unlinked ?? [] as $member)
                {{-- A member the sentence never names still has to be reachable, so it gets the
                     separate link the single-document path has always fallen back to. One per
                     member, each with its own id, because the description above names them all. --}}
                @if (($member['url'] ?? null) !== null)
                    <x-wirekit::link
                        :id="$memberLinkId($member)"
                        :href="$member['url']"
                        :hreflang="($member['locale'] ?? '') !== '' ? $member['locale'] : null"
                        external
                        :attributes="$memberTrigger($member)"
                    >{{ $member['title'] ?? '' }}</x-wirekit::link>
                @endif
            @endforeach

            @if ($cut === null && ($document['url'] ?? null) !== null && $wordingLink === null)
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

        {{-- COLLECTED HERE, RENDERED AFTER THE STACK. The dialog markup used to sit right
             here, which made every dialog a FLEX CHILD of the `gap="md"` stack above — a
             sibling of every field.

             ⚠️ THAT COSTS A FULL GAP EACH, AND IT IS NOT VISIBLE IN THE MARKUP. WireKit's outer
             modal node is a `<div>` carrying the Alpine state and NO `x-show`; what hides is the
             box inside it. So the wrapper is a VISIBLE flex child of height zero, and a flex
             container gives a zero-height child its whole gap anyway. Measured in a consumer that
             had built the same construction itself, on a form with six published documents:

               before:  pw→cb1 16   cb1→cb2 20   cb2→cb3 9   cb3→btn 17
               after:   pw→cb1 16   cb1→cb2  4   cb2→cb3 5   cb3→btn 17

             Uneven rather than merely wide, because only a document WITH a page gets a dialog:
             four of them between the first checkbox and the second is four zero-height children
             and five 4px gaps — the 20. It was reported from the screen twice.

             ⚠️ AND MOVING THEM ONE LEVEL OUT IS NOT ENOUGH, which the same consumer measured:
             after the group but still inside a container that has a gap, `cb3→btn` went from 17
             to 33. One uneven pair traded for a coarser one. The only thing that fixes it is a
             container with NO gap, which is what the root element below is.

             A modal is addressed by NAME, so where it sits in the document changes nothing about
             opening one. --}}
        @if ($dialog !== null)
            @php($deferredDialogs[] = [
                'name' => $dialogName,
                'title' => $document['title'],
                'url' => $document['url'],
                'locale' => $document['locale'],
                'dialog' => $dialog,
                'lang' => $lang,
            ])
        @endif
        @foreach ($members as $member)
            @if ($memberDialog($member) !== null)
                @php($deferredDialogs[] = [
                    'name' => $memberDialogName($member),
                    'title' => (string) ($member['title'] ?? ''),
                    'url' => $member['url'],
                    'locale' => $member['locale'],
                    'dialog' => $memberDialog($member),
                    'lang' => \Pushery\LegalConsent\Support\ContentLanguage::differingFrom($member['locale']),
                ])
            @endif
        @endforeach
    @endforeach
</x-wirekit::stack>

    {{-- The dialogs, outside every gapped container. See the collector above for what each one
         costs when it is inside one, and for the measurement that ruled out the halfway fix. --}}
    @foreach ($deferredDialogs as $deferred)
            <x-wirekit::modal :name="$deferred['name']" size="lg">
                <x-wirekit::modal.header>{{ $deferred['title'] }}</x-wirekit::modal.header>

                <x-wirekit::modal.body class="max-h-[65vh] overflow-y-auto" tabindex="0" role="region" :aria-label="$deferred['title']">
                    {{-- FETCHED ON FIRST OPEN, and kept afterwards. The text is the published,
                         already-sanitized HTML the legal page itself renders — one allowlist, applied
                         when it was stored, and the same bytes the ledger records as accepted. The
                         component puts it in as markup through the `body` ref: the CSP build refuses
                         the `x-html` directive before it reads any expression, and 0.38.0 opened onto
                         an empty box under it.

                         A failed fetch says so instead of leaving an empty box: a dialog that opens
                         onto nothing reads as a document with no content, and the reader is then
                         asked to tick that they read it. The way out is the page link in the footer
                         below, which is there in every state. --}}
                    {{-- TWO CALLS AND NOTHING ELSE, because Alpine's CSP build parses these
                         attributes with its own grammar rather than handing them to `eval`. That
                         grammar takes calls, member access, literals and operators — and refuses
                         arrow functions, optional chaining and more than one statement. This
                         carried a `fetch().then().catch()` chain inline, which is all three at
                         once, so under a policy without `unsafe-eval` it never ran: the dialog
                         opened onto nothing while `aria-haspopup` had already told a screen reader
                         it would work. The logic lives in the published script now, where none of
                         those limits apply, and an expression reduced to a call parses under both
                         builds — one template, either policy. --}}
                    <div x-data="legalConsentDialog(@js($deferred['name']), @js($deferred['dialog']))"
                         x-on:wirekit-modal-show.window="load($event)"
                         @if ($deferred['lang'] !== null) lang="{{ $deferred['lang'] }}" @endif>
                        <div x-show="state === 'ready'" x-ref="body"></div>
                        <p x-show="state === 'loading'" x-cloak>{{ __('legal-consent::ui.dialog_loading') }}</p>
                        <p x-show="state === 'failed'" x-cloak>{{ __('legal-consent::ui.dialog_failed') }}</p>
                    </div>
                </x-wirekit::modal.body>

                <x-wirekit::modal.footer>
                    {{-- THE PAGE, FROM INSIDE THE DIALOG, IN EVERY STATE. The anchor that opened this
                         dialog swallows its click to do so, so it can never be the way out of it —
                         and a body can stay empty for reasons the component never sees: a published
                         script older than this view, a script that is missing, a policy that refuses
                         what it does. Rendered by the server and depending on no script, this link is
                         what keeps the full text reachable before agreeing (§ 305 Abs. 2 BGB) whatever
                         happened above it. A new tab, so the form behind it keeps what was typed. --}}
                    <x-wirekit::link :href="$deferred['url']" :hreflang="$deferred['locale']" size="sm" external>{{ __('legal-consent::ui.dialog_open_page') }}</x-wirekit::link>
                    <x-wirekit::modal.close>
                        <x-wirekit::button size="sm" surface="soft">{{ __('legal-consent::ui.dialog_close') }}</x-wirekit::button>
                    </x-wirekit::modal.close>
                </x-wirekit::modal.footer>
            </x-wirekit::modal>
    @endforeach
</div>
