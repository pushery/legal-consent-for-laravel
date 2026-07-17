{{--
    WireKit-native view for the ReConsentForm Livewire component. Publish with
    `--tag=legal-consent-wirekit`; it overrides `legal-consent::livewire.reconsent-form`.

    Single root element (Livewire requires it). Needs `pushery/wirekit` in the host app.

    Never pre-checked (CJEU C-673/17 Planet49): `wire:model` binds to $accept[key], which starts
    empty. `$document->ui_wording` is the SNAPSHOTTED wording — it is what gets recorded as what
    the subject agreed to, so it renders verbatim and is never re-phrased here.
--}}
<div class="legal-consent-reconsent">
    @if ($pending->isEmpty())
        <x-wirekit::callout variant="success" icon>
            {{ __('legal-consent::ui.all_current') }}
        </x-wirekit::callout>
    @else
        <form wire:submit="submit">
            <x-wirekit::stack gap="md">
                @foreach ($pending as $document)
                    <x-wirekit::checkbox
                        :name="'legal_'.$document->key"
                        :id="'legal_'.$document->key"
                        wire:model="accept.{{ $document->key }}"
                        value="1"
                        :label="$document->ui_wording"
                    />
                @endforeach

                <x-wirekit::button type="submit" intent="primary" loading-target="submit">
                    {{ __('legal-consent::ui.submit') }}
                </x-wirekit::button>
            </x-wirekit::stack>
        </form>
    @endif
</div>
