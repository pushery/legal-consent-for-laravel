{{--
    WireKit-native view for the LegalTextEditor Livewire component. Publish with
    `--tag=legal-consent-wirekit`; it overrides `legal-consent::livewire.legal-text-editor`.

    Single root element. Needs `pushery/wirekit` + `@wirekitScripts` in the layout.

    The editor binds with a plain `wire:model`, which WireKit routes to the inner textarea.
    It still lives behind `wire:ignore` — that is WireKit's documented integration for this
    component, not a leftover of the old binding workaround.
--}}
{{-- POLLS ONLY WHILE A QUEUED TRANSLATION IS RUNNING, and only where one can run at all: the
     component answers false when `translation.queue` is off, so an application without a queue never
     polls. A dispatched job is invisible to the page that dispatched it, and without this the
     operator would click Translate, read that it is running, and have to guess when to reload.

     `?? false` because this view is ALSO rendered standalone, by the tests that check its markup
     against real components. There is no Livewire component behind those, so the flag is absent —
     and absent means "do not poll", which is the only thing a render without a component could
     honestly mean. Calling `$this->translating()` here instead threw on every one of them. --}}
<div @if ($translating ?? false) wire:poll.3s @endif>
    {{-- The label is assembled into an attribute bag, because a Blade `@if` INSIDE a component
         tag does not compile — it is emitted as literal text into the rendered attribute list.
         The landmark takes its name directly when the heading is gone: `aria-labelledby` pointing
         at a missing id names nothing, and an unnamed region is not an improvement on a duplicated
         title. `?? true` for a render outside the component, which passes no such flag. --}}
    @php($lcTitle = ($documentName ?? $key).' — '.($languageName ?? $locale))
    @php($lcSection = new Illuminate\View\ComponentAttributeBag(($heading ?? true)
        ? ['aria-labelledby' => 'lc-editor-heading']
        : ['aria-label' => $lcTitle]))
    <x-wirekit::stack gap="lg" as="section" :attributes="$lcSection">
        @if ($heading ?? true)
            <x-wirekit::heading :level="1" id="lc-editor-heading">{{ $lcTitle }}</x-wirekit::heading>
        @endif

        {{-- WHETHER A HUMAN HAS SIGNED OFF ON THESE EXACT BYTES, on the screen where that happens.
             `reviewState` reached this view and was rendered nowhere, so a reviewed draft looked
             exactly like an unreviewed one the moment the status line was replaced by the next
             action — on the one screen whose product is the sign-off.

             The state is labeled rather than shown as a bare word: a colored chip saying
             "Reviewed" beside a heading is not self-explanatory, and the color carries no meaning
             for a reader who cannot see it. --}}
        @if ($reviewState !== null)
            <x-wirekit::text size="sm">
                {{ __('legal-consent::ui.admin_review_state') }}
                <x-wirekit::badge :intent="$reviewState === 'reviewed' ? 'success' : 'warning'">
                    {{ __(\Pushery\LegalConsent\Enums\ReviewState::from($reviewState)->label()) }}
                </x-wirekit::badge>
            </x-wirekit::text>
        @endif

        {{-- WCAG 4.1.3: the stale-source warning stays always-present and only its inner text is
             gated, so a staleness that flips true as the RESULT of a Livewire action is still
             announced — inserting the whole alert together with its text would not be. Plain
             x-wirekit::text, NOT x-wirekit::alert: the alert is itself a role="status" region, and
             nesting one live region in another double-announces (same reason as the status region
             above). Assertive, matching the plain stub, for an irreversible-publish blocker. --}}
        <div role="alert" aria-live="assertive" wire:key="lc-editor-stale"
            @class(['sr-only' => ($staleNotice ?? null) === null])>
            {{-- `intent="warning"` so it reads as a warning rather than as part of the description
                 it sits under, and still a plain text rather than an alert: the surrounding div is
                 already the live region, and nesting one inside another double-announces. --}}
            @if ($staleNotice ?? null)
                <x-wirekit::text intent="warning">{{ __($staleNotice) }}</x-wirekit::text>
            @endif
        </div>

        {{-- KEYED ON THE SERVER'S BODY NONCE, and that key is the fix rather than decoration.
             `wire:ignore` holds the subtree out of Livewire's morph, which is what keeps the mounted
             editor alive — and it holds a server-side REPLACEMENT out as well. After Translate the
             visible editor still showed the old text while the hidden field already carried the new
             one, so the reader edited what they saw, the engine wrote its document back on the next
             keystroke, and Save stored the old text over the translation with nothing reporting it.
             A changed key makes Livewire replace the element instead of morphing it, so the editor
             is rebuilt from the new `:value`.

             `$bodyNonce ?? 0` for the same reason as `$translating ?? false` above: this view is
             also rendered standalone by the tests that check its markup against real components,
             where no component supplies it. A constant is the honest answer there — a render with
             no component behind it has no replacement to report. --}}
        <div wire:ignore wire:key="lc-editor-body-{{ $bodyNonce ?? 0 }}">
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

        {{-- A row that wraps, not a group. `button.group` joins its children into one line at
             every width, which is right for a segmented control and wrong for three separate acts.
             Measured at 390 px with German labels: the widest action ended at 548 px, 158 past the
             viewport. `row`'s own prop documents this case — "the per-page cure has been to add
             `wrap`" — so this uses WireKit's vocabulary rather than a class of its own. --}}
        <x-wirekit::row wrap gap="sm">
            <x-wirekit::button wire:click="save" loading-target="save" :disable-on-loading="false">{{ __('legal-consent::ui.admin_save') }}</x-wirekit::button>

            @unless ($isSource)
                <x-wirekit::button surface="outline" wire:click="translate" loading-target="translate" :disable-on-loading="false">{{ __('legal-consent::ui.admin_translate', ['locale' => $sourceLanguage]) }}</x-wirekit::button>
            @endunless

            {{-- Only while there is something to stamp. Offered on an already-reviewed draft it
                 reports success and changes nothing, which is what teaches a reader that the button
                 means nothing — and this is the button the publish gate depends on.

                 It comes back on its own: every write to a draft's body resets the state to Draft,
                 so a save puts the button back with the work it applies to. --}}
            @if ($reviewState !== 'reviewed')
                <x-wirekit::button surface="outline" wire:click="markReviewed" loading-target="markReviewed" :disable-on-loading="false">{{ __('legal-consent::ui.admin_mark_reviewed') }}</x-wirekit::button>
            @endif

            {{-- OFFERED ONLY WHERE IT CAN DO SOMETHING: a translation that has a draft. The source
                 draft cannot be discarded — every translation measures its freshness against it and
                 falls back to it — and a locale with nothing written has nothing to take away.

                 Behind a real confirmation, and an alert-dialog rather than the browser's own
                 confirm: the same reasoning the withdrawal on the settings screen writes down, and
                 the same reason it is not a plain button. Discarding is not undoable from here. --}}
            @if (! $isSource && $reviewState !== null)
                <x-wirekit::alert-dialog name="lc-editor-discard">
                    <x-slot:trigger>
                        <x-wirekit::button intent="danger" surface="outline">{{ __('legal-consent::ui.admin_discard') }}</x-wirekit::button>
                    </x-slot:trigger>

                    <x-wirekit::alert-dialog.title>{{ __('legal-consent::ui.admin_discard_confirm_title') }}</x-wirekit::alert-dialog.title>

                    <x-wirekit::alert-dialog.description>{{ __('legal-consent::ui.admin_discard_confirm_body', ['locale' => $locale]) }}</x-wirekit::alert-dialog.description>

                    <x-wirekit::alert-dialog.actions>
                        <x-wirekit::alert-dialog.cancel>{{ __('legal-consent::ui.cancel') }}</x-wirekit::alert-dialog.cancel>

                        <x-wirekit::button intent="danger" wire:click="discard" loading-target="discard" :disable-on-loading="false">{{ __('legal-consent::ui.admin_discard') }}</x-wirekit::button>
                    </x-wirekit::alert-dialog.actions>
                </x-wirekit::alert-dialog>
            @endif
            {{-- WCAG 4.1.3: Save / Translate / Mark reviewed announce their result here, BESIDE THE
             BUTTONS THEY BELONG TO. It used to sit above the field, so the result of pressing Save
             appeared above a field whose button is below it — on a phone, off screen. The region is
                 ALWAYS in the DOM so the update is spoken — a live region inserted together with its
                 text is not announced. The text is a plain x-wirekit::text, NOT an x-wirekit::alert:
                 the alert is itself a role="status" region, and nesting one live region in another
                 double-announces and conflicts on politeness. --}}
            {{-- `sr-only` while EMPTY, and only then — the pattern the consent panel already carries,
                 for the same measured reason there: an empty region has no height but is still a
                 child of the stack, so it takes a gap.

                 On this region it is consistency, not a measured saving, and that is written here
                 so nobody re-derives it. Measured at 390 px: with the hiding and without it, the
                 widest action ends at 390 px either way — this region sits AFTER the buttons now, so
                 an empty one has nothing to push. The stale region above is where it does act (96 px
                 against 90 between the heading and the field). Treating the two live regions of one
                 screen differently would cost the next reader more than the attribute does. --}}
            <div role="status" aria-live="polite" wire:key="lc-editor-status"
                @class(['sr-only' => ($status ?? '') === ''])>
                @if ($status !== '')
                    <div wire:key="lc-editor-status-{{ $statusNonce }}"><x-wirekit::text>{{ $status }}</x-wirekit::text></div>
                @endif
            </div>
        </x-wirekit::row>

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

            {{-- THE CLASSIFICATION, beside the dates rather than apart from them: one release
                 carries both, and five of the seven fields were built here while the two that say
                 what KIND of change it is were not. A release from this screen therefore froze a
                 contract change with no regime and no class, and said nothing about it.

                 The regime is a select over the publisher's own list, because it refuses one it
                 does not know; the class is free text, because it takes any and the command line
                 calls it a tag. --}}
            <x-wirekit::select wire:model="regime" name="regime" :label="__('legal-consent::ui.admin_deemed_regime')">
                <option value="">{{ __('legal-consent::ui.admin_deemed_regime_none') }}</option>
                {{-- THE PUBLISHER'S OWN LIST, read from it rather than written out here: it
                     refuses a regime it does not know, so a second vocabulary on this screen would
                     be free to drift -- and the drift shows up as a refusal on a release somebody
                     has already scheduled.

                     Read as the constant, not through a component method. `$this` is bound only
                     while Livewire renders this view; the package's own view tests render it
                     directly, and `$this->regimes()` died there with "Using $this when not in
                     object context" -- a published stub has to render wherever a consumer puts it.
                     Measured on the integration gate, not guessed. --}}
                @foreach (\Pushery\LegalConsent\Support\LegalDocumentPublisher::REGIMES as $available)
                    <option value="{{ $available }}">{{ $available }}</option>
                @endforeach
            </x-wirekit::select>

            <x-wirekit::input wire:model="changeClass" name="changeClass" :label="__('legal-consent::ui.admin_deemed_change_class')" :hint="__('legal-consent::ui.admin_deemed_change_class_hint')" />

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
        <x-wirekit::card as="section" aria-label="{{ __('legal-consent::ui.admin_preview_label') }}">
            {{-- Through `card.body` and `prose`, because a preview that does not look like its
                 subject invites somebody to "correct" spacing that was never wrong: the published
                 page sets its text as prose, and headings, lists and tables render differently
                 without it. --}}
            <x-wirekit::card.body>
                <x-wirekit::prose>{!! $preview !!}</x-wirekit::prose>
            </x-wirekit::card.body>
        </x-wirekit::card>
    </x-wirekit::stack>
</div>
