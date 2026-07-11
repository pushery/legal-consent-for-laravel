{{--
    View for the ReConsentForm Livewire component. Single root element. Lists the documents
    the subject still owes; each checkbox binds to $accept[key]. Never pre-checked (Planet49);
    submit records the ticked documents. Style freely.
--}}
<div class="legal-consent-reconsent">
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
                </div>
            @endforeach

            <button type="submit">{{ __('legal-consent::ui.submit') }}</button>
        </form>
    @endif
</div>
