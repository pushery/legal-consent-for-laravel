{{--
    View for the ReConsentForm Livewire component. Single root element. Lists the documents
    the subject still owes; each checkbox binds to $accept[key]. Never pre-checked (Planet49);
    submit records the ticked documents. Style freely.
--}}
<div class="legal-consent-reconsent">
    {{-- WCAG 4.1.3 + 2.4.3: on submit the outstanding list can empty and the whole <form> is
         replaced by the "all current" message. That message is inserted WITH its text (so it is not
         announced) and the focused submit button vanishes. This always-present polite region carries
         the confirmation instead and takes focus so it does not drop to <body>. Focus is driven by
         x-effect, not x-init: x-init runs once and does not re-run on a Livewire morph. --}}
    <p role="status" aria-live="polite" tabindex="-1" wire:key="lc-reconsent-status"
        x-effect="$wire.statusNonce > 0 && $el.focus()"><span wire:key="lc-reconsent-status-{{ $statusNonce }}">{{ $status ?? '' }}</span></p>

    @if ($pending->isEmpty())
        <p>{{ __('legal-consent::ui.all_current') }}</p>
    @else
        <form wire:submit="submit">
            @foreach ($pending as $document)
                <div class="legal-consent-field">
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

            <button type="submit">{{ __('legal-consent::ui.submit') }}</button>
        </form>
    @endif
</div>
