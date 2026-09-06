{{--
    WireKit-native view for the ReConsentForm Livewire component. Publish with
    `--tag=legal-consent-wirekit`; it overrides `legal-consent::livewire.reconsent-form`.

    Single root element (Livewire requires it). Needs `pushery/wirekit` in the host app.

    Never pre-checked (CJEU C-673/17 Planet49): `wire:model` binds to $accept[key], which starts
    empty. `$document->ui_wording` is the SNAPSHOTTED wording — it is what gets recorded as what
    the subject agreed to, so it renders verbatim and is never re-phrased here.
--}}
<div class="legal-consent-reconsent">
    {{-- WCAG 4.1.3 + 2.4.3: on submit the outstanding list can empty and the whole <form> is replaced
         by the "all current" callout — inserted WITH its text, so not announced, and the focused submit
         button vanishes. This always-present region carries the confirmation and takes focus so it does
         not drop to <body>. The text is a plain x-wirekit::text, NOT a nested alert/callout (those are
         themselves role="status" — nesting double-announces). Focus via x-effect, not x-init. --}}
    <div role="status" aria-live="polite" tabindex="-1" wire:key="lc-reconsent-status"
        x-effect="$wire.statusNonce > 0 && $el.focus()">
        @if (($status ?? '') !== '')
            <div wire:key="lc-reconsent-status-{{ $statusNonce }}"><x-wirekit::text>{{ $status }}</x-wirekit::text></div>
        @endif
    </div>

    @if ($pending->isEmpty())
        <x-wirekit::callout intent="success" icon>
            {{ __('legal-consent::ui.all_current') }}
        </x-wirekit::callout>
    @else
        <form wire:submit="submit">
            <x-wirekit::stack gap="md">
                @foreach ($pending as $document)
                    {{-- `lang` on the field, because the wording reaches the checkbox as a prop and
                         everything inside is that document's text anyway. See the plain gate. --}}
                    @php($lang = \Pushery\LegalConsent\Support\ContentLanguage::differingFrom($document->locale))
                    {{-- `wire:key` for the same reason as the plain gate: this loop SHRINKS, so a
                         keyless morph reuses the node at index 0 for a different document, and a
                         ticked box lives only as a DOM property because no `checked` attribute is
                         rendered. Keyed on the document, not the index. --}}
                    <x-wirekit::stack gap="xs" wire:key="lc-pending-{{ $document->key }}" :lang="$lang">
                        <x-wirekit::checkbox
                            :name="'legal_'.$document->key"
                            :id="'legal_'.$document->key"
                            wire:model="accept.{{ $document->key }}"
                            value="1"
                            :label="$document->ui_wording"
                        />

                        {{-- The subject cannot leave this screen without agreeing, so they must be
                             able to read what they are agreeing to (Art. 7(2), recital 42). Rendered
                             only when the host configured `legal-consent.document_url`. --}}
                        @if (isset($urls[$document->key]))
                            <x-wirekit::link :href="$urls[$document->key]" external>
                                {{ __('legal-consent::ui.read_document', ['title' => $document->title]) }}
                            </x-wirekit::link>
                        @endif
                    </x-wirekit::stack>
                @endforeach

                {{-- ⚠️ `loading-target` ALONE RENDERS NOTHING, AND THAT IS WHAT STOOD HERE. Read in
                     x-wirekit::button: the spinner sits behind `@if($declarativeLoading) … @elseif($loading)`
                     and `wire:loading.attr="disabled"` behind `@if($loading && …)`. Both gate on `loading`,
                     which this call never set — `loading-target` only SCOPES a spinner that was switched on
                     elsewhere. So the twin looked like it had a busy state and had none.

                     Not repaired by adding `loading`, because the kit's busy state disables the button, and
                     `disabled` blurs the control the subject just activated: focus falls to <body> for the
                     whole in-flight window, on the one screen a subject cannot leave (WCAG 2.4.3). Instead
                     the same contract as the plain twin — `aria-busy` through the attribute bag, which
                     x-wirekit::button renders verbatim (`$attributes->except('rel')`), plus a visible label
                     beside it. Twins must not disagree about how a wait is reported. --}}
                <x-wirekit::button type="submit" intent="primary" wire:loading.attr="aria-busy" wire:target="submit">
                    {{ __('legal-consent::ui.submit') }}
                </x-wirekit::button>
                <x-wirekit::text wire:loading wire:target="submit" class="legal-consent-busy">{{ __('legal-consent::ui.working') }}</x-wirekit::text>
            </x-wirekit::stack>
        </form>
    @endif
</div>
