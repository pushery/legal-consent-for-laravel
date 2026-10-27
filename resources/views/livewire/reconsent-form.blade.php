{{--
    View for the ReConsentForm Livewire component. Single root element. Lists the documents
    the subject still owes; each checkbox binds to $accept[key]. Never pre-checked (Planet49);
    submit records the ticked documents. Style freely.
--}}
<div class="legal-consent-reconsent">
    {{-- WCAG 4.1.3 + 2.4.3: on submit the outstanding list can empty and the whole <form> is
         replaced by the "all current" message. That message is inserted with its text (so it is not
         announced) and the focused submit button vanishes. This always-present polite region carries
         the confirmation instead and takes focus so it does not drop to <body>. Focus is driven by
         x-effect, not x-init: x-init runs once and does not re-run on a Livewire morph. --}}
    <p role="status" aria-live="polite" tabindex="-1" wire:key="lc-reconsent-status"
        x-effect="$wire.statusNonce > 0 && $el.focus()"><span wire:key="lc-reconsent-status-{{ $statusNonce }}">{{ $status ?? '' }}</span></p>

    {{-- The deemed-consent changes that have not taken effect yet. The notice's call to action and
         the banner's "Review or object" both lead here, so this is where the objection has to be
         possible: a window in which this page takes none runs out, and the change then binds on
         silence (§ 308 Nr. 5 BGB). After the window, the free termination the notice promises until
         the change takes effect stays on offer alone. Each row repeats the notice's own sentences.
         The termination appears only where the notice named it, and each control appears only where
         this mount allows it. Both write an irreversible row, so each asks once before it does. --}}
    @php($deemed ??= [])
    @if ($deemed !== [])
        <section class="legal-consent-objections" aria-labelledby="lc-objections-heading" wire:key="lc-objections">
            <h2 id="lc-objections-heading">{{ __('legal-consent::ui.upcoming_changes_heading') }}</h2>
            <ul>
                @foreach ($deemed as $item)
                    @php($lang = \Pushery\LegalConsent\Support\ContentLanguage::differingFrom($item['locale']))
                    <li wire:key="lc-deemed-{{ $item['key'] }}">
                        @if ($item['url'] !== null)
                            <a href="{{ $item['url'] }}"@if ($lang !== null) hreflang="{{ $lang }}" lang="{{ $lang }}"@endif target="_blank" rel="noopener noreferrer">{{ $item['title'] }}</a>
                        @else
                            <span @if ($lang !== null) lang="{{ $lang }}"@endif>{{ $item['title'] }}</span>
                        @endif
                        (v{{ $item['version'] }})
                        @if ($item['objection_date'] !== null)
                            <span>{{ __('legal-consent::notifications.deemed.warning', ['deadline' => $item['objection_date']]) }}</span>
                        @endif
                        @if ($item['terminable'] && $item['effective_date'] !== null)
                            <span>{{ __('legal-consent::notifications.deemed.termination', ['effective' => $item['effective_date']]) }}</span>
                        @endif
                        @if ($item['objectable'])
                            <button type="button" aria-label="{{ __('legal-consent::ui.object_for', ['title' => $item['title']]) }}" wire:click="object(@js($item['key']))" wire:confirm="{{ __('legal-consent::ui.object_confirm_body', ['title' => $item['title']]) }}" wire:loading.attr="aria-busy" wire:target="object(@js($item['key']))">
                                {{ __('legal-consent::ui.object') }}
                            </button>
                        @endif
                        @if ($item['terminable'])
                            <button type="button" aria-label="{{ __('legal-consent::ui.terminate_for', ['title' => $item['title']]) }}" wire:click="terminate(@js($item['key']))" wire:confirm="{{ __('legal-consent::ui.terminate_confirm_body', ['title' => $item['title']]) }}" wire:loading.attr="aria-busy" wire:target="terminate(@js($item['key']))">
                                {{ __('legal-consent::ui.terminate') }}
                            </button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($pending->isEmpty())
        {{-- "Nothing to do" would contradict an open objection window above it. --}}
        @if ($deemed === [])
            <p>{{ __('legal-consent::ui.all_current') }}</p>
        @endif
    @else
        <form wire:submit="submit">
            @foreach ($pending as $document)
                {{-- The wording can be in a language this page is not in: the resolver falls back
                     to `fallback_locale` and then `default_locale`, so a mandatory document a
                     visitor's language does not have appears in its own. Without `lang` a screen
                     reader speaks it with the page's phonetics — on the one screen the subject
                     cannot leave without agreeing (Art. 7(1)/(2), WCAG 3.1.2). See
                     ContentLanguage; null when it matches the page, and nothing is emitted. --}}
                @php($lang = \Pushery\LegalConsent\Support\ContentLanguage::differingFrom($document->locale))
                {{-- `wire:key` on the one loop that shrinks and carries state: `pending` goes
                     [marketing, privacy, terms] → [privacy, terms] after a submit, so the node
                     at index 0 changes identity from `legal_marketing` to `legal_privacy`. And the
                     server renders no `checked` attribute — a ticked box lives only as a DOM
                     property, so there is nothing in the markup for a keyless morph to reset it
                     with. A box that stayed ticked across that swap is a pre-ticked checkbox the
                     subject did not tick in this round, which is the Planet49 condition (C-673/17)
                     this file's own header cites.
                     Keyed on the document, not on the index — the index is precisely what moves. --}}
                <div class="legal-consent-field" wire:key="lc-pending-{{ $document->key }}"@if ($lang !== null) lang="{{ $lang }}"@endif>
                    <label for="legal_{{ $document->key }}">
                        <input
                            type="checkbox"
                            id="legal_{{ $document->key }}"
                            wire:model="accept.{{ $document->key }}"
                            value="1"
                        >
                        <span>{{ $document->ui_wording }}</span>
                    </label>

                    {{-- The subject cannot leave this screen without agreeing, so they must be able
                         to read what they are agreeing to (Art. 7(2), recital 42). The link appears
                         only when the host configured `legal-consent.document_url`; without it the
                         wording renders alone, exactly as before. --}}
                    @if (isset($urls[$document->key]))
                        <a href="{{ $urls[$document->key] }}" target="_blank" rel="noopener noreferrer">
                            {{ __('legal-consent::ui.read_document', ['title' => $document->title]) }}
                        </a>
                    @endif
                </div>
            @endforeach

            {{-- `aria-busy`, not `disabled`, and the focus is why. `wire:loading.attr="disabled"` is the
                 common idiom, but it blurs the very button the subject just activated, so focus falls to
                 <body> for the whole in-flight window — the same WCAG 2.4.3 failure the status region's
                 focus move exists to prevent, except here it would happen on every request rather than
                 in one edge case. `aria-busy="true"` keeps the control focusable and in the tab order and
                 still reports the wait. The WireKit twins carry the identical pair, passed through
                 x-wirekit::button's attribute bag, so a publish flag cannot change how a wait is reported. --}}
            <button type="submit" wire:loading.attr="aria-busy" wire:target="submit">{{ __('legal-consent::ui.submit') }}</button>
            {{-- The sighted half of the same state. It is on this screen and no other because this is
                 the one a subject cannot leave without acting, so an unexplained pause is worst here. --}}
            <span wire:loading wire:target="submit" class="legal-consent-busy">{{ __('legal-consent::ui.working') }}</span>
        </form>
    @endif
</div>
