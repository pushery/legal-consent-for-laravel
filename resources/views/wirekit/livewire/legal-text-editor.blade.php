{{--
    WireKit-native view for the LegalTextEditor Livewire component. Publish with
    `--tag=legal-consent-wirekit`; it overrides `legal-consent::livewire.legal-text-editor`.

    Single root element. Needs `pushery/wirekit` + `@wirekitScripts` in the layout.

    The editor binds with a plain `wire:model`, which WireKit routes to the inner textarea.
    It still lives behind `wire:ignore` — that is WireKit's documented integration for this
    component, not a leftover of the old binding workaround.
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
                <div wire:key="lc-editor-status-{{ $statusNonce }}"><x-wirekit::text>{{ $status }}</x-wirekit::text></div>
            @endif
        </div>

        {{-- WCAG 4.1.3: the stale-source warning stays always-present and only its inner text is
             gated, so a staleness that flips true as the RESULT of a Livewire action is still
             announced — inserting the whole alert together with its text would not be. Plain
             x-wirekit::text, NOT x-wirekit::alert: the alert is itself a role="status" region, and
             nesting one live region in another double-announces (same reason as the status region
             above). Assertive, matching the plain stub, for an irreversible-publish blocker. --}}
        <div role="alert" aria-live="assertive" wire:key="lc-editor-stale">
            @if ($stale)
                <x-wirekit::text>{{ __('legal-consent::ui.admin_stale') }}</x-wirekit::text>
            @endif
        </div>

        <div wire:ignore>
            {{-- The current draft body seeds the editor via :value (the component reads the `value`
                 prop, never a slot — a slot here would silently render nothing on edit). Content
                 flows back through a plain wire:model: WireKit routes it to the inner
                 <textarea x-ref="input">, the element the editor writes to, and the editor
                 dispatches a bubbling input event on every change. Deferred on purpose (no .live) —
                 the bytes ride along with the save/translate/markReviewed action, so typing costs no
                 round-trip.

                 wire:ignore STAYS. It is not part of the retired binding workaround: it is the
                 integration WireKit itself documents for this component. Livewire would otherwise
                 morph the editor subtree, keeping the mounted ProseMirror node while Alpine builds a
                 fresh component — two views on one node, content rendered twice, and every toolbar
                 command throwing on a stale view.

                 The toolbar is pinned explicitly, not inherited. Every command here produces markup
                 the sanitizer keeps (LegalHtmlSanitizer::ALLOWED) — offer one it strips and an admin
                 formats a clause that silently disappears the moment it is stored. Leaving the
                 toolbar on WireKit's `basic` preset would put that set under WireKit's control: a
                 future release widening the preset would hand this editor a command whose output
                 cannot survive, without a line changing here. --}}
            <x-wirekit::editor :value="$body" wire:model="body">
                <x-slot:toolbar>
                    {{-- No x-ref here: WireKit wraps a custom toolbar slot in its own
                         <div x-ref="toolbar">, and a second one would win and orphan the wrapper. --}}
                    <x-wirekit::editor.toolbar :commands="['bold', 'italic', 'strike', 'link', '|', 'bullet-list', 'ordered-list']" />
                </x-slot:toolbar>
            </x-wirekit::editor>
        </div>

        <x-wirekit::button.group>
            <x-wirekit::button wire:click="save" loading-target="save" :disable-on-loading="false">{{ __('legal-consent::ui.admin_save') }}</x-wirekit::button>

            @unless ($isSource)
                <x-wirekit::button surface="outline" wire:click="translate" loading-target="translate" :disable-on-loading="false">{{ __('legal-consent::ui.admin_translate', ['locale' => $sourceLocale]) }}</x-wirekit::button>
            @endunless

            <x-wirekit::button surface="outline" wire:click="markReviewed" loading-target="markReviewed" :disable-on-loading="false">{{ __('legal-consent::ui.admin_mark_reviewed') }}</x-wirekit::button>
        </x-wirekit::button.group>

        {{-- RELEASE WITH AN OBJECTION WINDOW — the WireKit twin of the plain stub's form.
             Same placement decision and the same reason: a deemed-consent release binds people by
             their SILENCE (§ 308 Nr. 5 BGB), so it belongs where somebody has actually read the
             text, not behind a button on an overview grid. `date-picker` and `toggle` are used
             here because WireKit owns their keyboard and screen-reader behavior; the plain stub
             uses native `type="date"` and checkboxes for the same reason in the other direction. --}}
        <x-wirekit::heading :level="2">{{ __('legal-consent::ui.admin_deemed_heading') }}</x-wirekit::heading>
        <x-wirekit::text>{{ __('legal-consent::ui.admin_deemed_explainer') }}</x-wirekit::text>

        <x-wirekit::stack gap="sm">
            <x-wirekit::date-picker wire:model="announceAt" name="announceAt" :label="__('legal-consent::ui.admin_deemed_announce')" />
            <x-wirekit::date-picker wire:model="objectionDeadline" name="objectionDeadline" :label="__('legal-consent::ui.admin_deemed_deadline')" />
            <x-wirekit::date-picker wire:model="enforceAt" name="enforceAt" :label="__('legal-consent::ui.admin_deemed_enforce')" />

            <x-wirekit::toggle wire:model="offersTermination" :label="__('legal-consent::ui.admin_deemed_offers_termination')" />
            <x-wirekit::toggle wire:model="keepsUnmodified" :label="__('legal-consent::ui.admin_deemed_keeps_unmodified')" />

            <x-wirekit::button wire:click="releaseDeemed" loading-target="releaseDeemed" :disable-on-loading="false">{{ __('legal-consent::ui.admin_deemed_submit') }}</x-wirekit::button>
        </x-wirekit::stack>

        <x-wirekit::heading :level="2">{{ __('legal-consent::ui.admin_preview') }}</x-wirekit::heading>
        {{-- The already-sanitized stored bytes — a true fixpoint of what a publish will freeze. --}}
        {{-- `as="section"` for the same reason as the plain twin: `x-wirekit::card` renders a
             `<div>` by default, a div with no role maps to `generic`, and ARIA forbids naming a
             generic element — the label was discarded. And the label key is the one the plain twin
             uses: this said `admin_preview`, the heading's own text, so the region would have
             announced the same words as the heading right above it. --}}
        <x-wirekit::card as="section" aria-label="{{ __('legal-consent::ui.admin_preview_label') }}">{!! $preview !!}</x-wirekit::card>
    </x-wirekit::stack>
</div>
