{{--
    WireKit-native view for the LegalTextEditor Livewire component. Publish with
    `--tag=legal-consent-wirekit`; it overrides `legal-consent::livewire.legal-text-editor`.

    Single root element. Needs `pushery/wirekit` + `@wirekitScripts` in the layout.

    Editor binding (WireKit gap h): `wire:model` on <x-wirekit::editor> silently loses input under
    Livewire 4.3.x — the attribute lands on the wrapper div, not the textarea, and Livewire's .self
    guard rejects the bubbled input event. So the content is bound explicitly via
    $wire.set('body', …, false), and the editor lives behind wire:ignore so Livewire never tears its
    DOM down. Retire this the moment WireKit forwards wire:* to the editor's inner textarea.
--}}
<div>
    <x-wirekit::stack gap="lg" as="section" aria-labelledby="lc-editor-heading">
        <x-wirekit::heading :level="1" id="lc-editor-heading">{{ $key }} — {{ $locale }}</x-wirekit::heading>

        {{-- WCAG 4.1.3: Save / Translate / Mark reviewed announce their result here. The region is
             ALWAYS in the DOM so the update is spoken — a live region inserted together with its
             text is not announced. The text is a plain x-wirekit::text, NOT an x-wirekit::alert:
             the alert is itself a role="status" region, and nesting one live region in another
             double-announces and conflicts on politeness. --}}
        <div role="status" aria-live="polite" wire:key="lc-editor-status">
            @if ($status !== '')
                <x-wirekit::text>{{ $status }}</x-wirekit::text>
            @endif
        </div>

        @if ($stale)
            <x-wirekit::alert variant="warning">{{ __('legal-consent::ui.admin_stale') }}</x-wirekit::alert>
        @endif

        <div wire:ignore>
            {{-- The current draft body seeds the editor via :value (the component reads the `value`
                 prop, never a slot — a slot here would silently render nothing on edit). Content
                 flows back the other way through the input binding below: with wire:model unusable
                 on the wrapper (WireKit gap h), every keystroke is pushed to the Livewire `body`
                 property explicitly, without a network round-trip (the `false` third arg). --}}
            <x-wirekit::editor
                :value="$body"
                x-data
                x-on:input="$wire.set('body', $event.target.value, false)"
            />
        </div>

        <x-wirekit::button.group>
            <x-wirekit::button wire:click="save">{{ __('legal-consent::ui.admin_save') }}</x-wirekit::button>

            @unless ($isSource)
                <x-wirekit::button surface="outline" wire:click="translate">{{ __('legal-consent::ui.admin_translate', ['locale' => $sourceLocale]) }}</x-wirekit::button>
            @endunless

            <x-wirekit::button surface="outline" wire:click="markReviewed">{{ __('legal-consent::ui.admin_mark_reviewed') }}</x-wirekit::button>
        </x-wirekit::button.group>

        <x-wirekit::heading :level="2">{{ __('legal-consent::ui.admin_preview') }}</x-wirekit::heading>
        {{-- The already-sanitized stored bytes — a true fixpoint of what a publish will freeze. --}}
        <x-wirekit::card aria-label="{{ __('legal-consent::ui.admin_preview') }}">{!! $preview !!}</x-wirekit::card>
    </x-wirekit::stack>
</div>
