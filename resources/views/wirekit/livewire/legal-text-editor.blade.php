{{--
    WireKit-native view for the LegalTextEditor Livewire component. Publish with
    `--tag=legal-consent-wirekit`; it overrides `legal-consent::livewire.legal-text-editor`.

    Single root element. Needs `pushery/wirekit` + `@wirekitScripts` in the layout.

    The editor binds with a plain `wire:model`, which WireKit routes to the inner textarea.
    It still lives behind `wire:ignore` — that is WireKit's documented integration for this
    component, not a leftover of the old binding workaround.
--}}
{{-- Polls only while a queued translation is running, and only where one can run at all: the
     component answers false when `translation.queue` is off, so an application without a queue never
     polls. A dispatched job is invisible to the page that dispatched it, and without this the
     operator would click Translate, read that it is running, and have to guess when to reload.

     `?? false` because this view can also be rendered standalone, with no Livewire component
     behind it. The flag is then absent, and absent means "do not poll", which is the only thing a
     render without a component can honestly mean; calling `$this->translating()` here would throw.

     Each tick calls `pollTranslation`, which answers without a render while the run is still going.
     The tick after it ends renders, takes the result, and stops the poll. --}}
<div @if ($translating ?? false) wire:poll.3s="pollTranslation" @endif>
    {{-- The label is assembled into an attribute bag, because a Blade `@if` inside a component
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

        {{-- Whether a human has signed off on these exact bytes, on the screen where that happens.
             Without it a reviewed draft looks exactly like an unreviewed one once the status line
             is replaced by the next action, on the one screen whose product is the sign-off.

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
             gated, so a staleness that flips true as the result of a Livewire action is still
             announced — inserting the whole alert together with its text would not be. Plain
             x-wirekit::text, not x-wirekit::alert: the alert is itself a role="status" region, and
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

        {{-- Keyed on the server's body nonce, which is what lets a translation reach the editor.
             `wire:ignore` holds the subtree out of Livewire's morph, which is what keeps the mounted
             editor alive, and it holds a server-side replacement out as well: after Translate the
             visible editor would still show the old text while the hidden field already carried the
             new one, and the next keystroke and Save would write the old text over the translation.
             A changed key makes Livewire replace the element instead of morphing it, so the editor
             is rebuilt from the new `:value`.

             `$bodyNonce ?? 0` for the same reason as `$translating ?? false` above: rendered
             standalone, no component supplies it, and a render with no component behind it has no
             replacement to report.

             Only a person's edit reaches `body`. From 2.56.0, below the floor of these views, the editor
             no longer reports its own serialization of the text as input when it loads, so a save
             without an edit stores the bytes the draft was opened with. --}}
        {{-- The text and the buttons that store it, one scope of WireKit's unsaved hint: the text counts
             as unsaved until a save, the hint beside Save says so, and leaving the page asks, a
             wire:navigate link in the kit's dialog and a reload or a closed tab in the browser's own.
             `until-saved`, because every request sends the deferred text along without storing it; the
             component dispatches wirekit:saved for this scope once a save or a discard went through.

             A stack rather than a plain element, so the buttons stand apart from the editor: the outer
             stack's gap reaches only its own children, and this scope is one of them. --}}
        <x-wirekit::stack gap="sm" x-wk-unsaved.until-saved.confirm="{{ \Pushery\LegalConsent\Livewire\LegalTextEditor::UNSAVED_TEXT }}">
        <div wire:ignore wire:key="lc-editor-body-{{ $bodyNonce ?? 0 }}">
            {{-- The current draft body seeds the editor via :value (the component reads the `value`
                 prop, never a slot — a slot here would silently render nothing on edit). Content
                 flows back through a plain wire:model: WireKit routes it to the inner
                 <textarea x-ref="input">, the element the editor writes to, and the editor
                 dispatches a bubbling input event on every change. Deferred on purpose (no .live) —
                 the bytes ride along with the save/translate/markReviewed action, so typing costs no
                 round-trip.

                 wire:ignore stays. It is not part of the retired binding workaround: it is the
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
             every width, which is right for a segmented control and wrong for three separate acts:
             at 390 px with German labels the widest action would end at 548 px, 158 past the
             viewport. `row`'s own prop documents this case — "the per-page cure has been to add
             `wrap`" — so this uses WireKit's vocabulary rather than a class of its own. --}}
        <x-wirekit::row wrap gap="sm">
            {{-- Says beside Save that the text holds what the draft does not have yet. --}}
            <x-wirekit::unsaved-hint />
            <x-wirekit::button wire:click="save" loading-target="save" :disable-on-loading="false">{{ __('legal-consent::ui.admin_save') }}</x-wirekit::button>

            {{-- Save, then a short dialog of every setting the release takes, and the release on
                 its confirmation. --}}
            @if (($releaseModes ?? []) !== [])
                <x-wirekit::button surface="outline" wire:click="saveAndRelease" loading-target="saveAndRelease" :disable-on-loading="false">{{ __('legal-consent::ui.admin_save_and_release') }}</x-wirekit::button>
            @endif

            {{-- Disabled while a translation of this draft runs, and it names the wait: a second press
                 would pay a per-call translator again for the same text. A press that arrives inside
                 the round trip is answered by the component instead. --}}
            @unless ($isSource)
                <x-wirekit::button surface="outline" wire:click="translate" loading-target="translate" :disable-on-loading="false" :disabled="$translating ?? false">{{ ($translating ?? false) ? __('legal-consent::ui.admin_translate_running') : __('legal-consent::ui.admin_translate', ['locale' => $sourceLanguage]) }}</x-wirekit::button>
            @endunless

            {{-- Only while there is something to stamp. Offered on an already-reviewed draft it
                 reports success and changes nothing, which is what teaches a reader that the button
                 means nothing — and this is the button the publish gate depends on.

                 It comes back on its own: every write to a draft's body resets the state to Draft,
                 so a save puts the button back with the work it applies to. --}}
            @if ($reviewState !== null && $reviewState !== 'reviewed' && ! ($translating ?? false))
                <x-wirekit::button surface="outline" wire:click="markReviewed" loading-target="markReviewed" :disable-on-loading="false">{{ __('legal-consent::ui.admin_mark_reviewed') }}</x-wirekit::button>
            @endif

            {{-- Offered only where it can do something: a translation that has a draft. The source
                 draft cannot be discarded — every translation measures its freshness against it and
                 falls back to it — and a locale with nothing written has nothing to take away.

                 Behind a real confirmation, and an alert-dialog rather than the browser's own
                 confirm: the same reasoning the withdrawal on the settings screen writes down, and
                 the same reason it is not a plain button. Discarding is not undoable from here. --}}
            @if (! $isSource && $reviewState !== null)
                {{-- It closes with the re-render its action causes, and says so, so an application that turns close-on-confirm on for every dialog does not ask this one for a confirm control. --}}
                <x-wirekit::alert-dialog name="lc-editor-discard" :close-on-confirm="false">
                    <x-slot:trigger>
                        <x-wirekit::button intent="danger" surface="outline">{{ __('legal-consent::ui.admin_discard') }}</x-wirekit::button>
                    </x-slot:trigger>

                    <x-wirekit::alert-dialog.title>{{ __('legal-consent::ui.admin_discard_confirm_title') }}</x-wirekit::alert-dialog.title>

                    {{-- The language by its name, as the heading above names it. --}}
                    <x-wirekit::alert-dialog.description>{{ __('legal-consent::ui.admin_discard_confirm_body', ['locale' => $languageName ?? $locale]) }}</x-wirekit::alert-dialog.description>

                    <x-wirekit::alert-dialog.actions>
                        <x-wirekit::alert-dialog.cancel>{{ __('legal-consent::ui.cancel') }}</x-wirekit::alert-dialog.cancel>

                        <x-wirekit::button intent="danger" wire:click="discard" loading-target="discard" :disable-on-loading="false">{{ __('legal-consent::ui.admin_discard') }}</x-wirekit::button>
                    </x-wirekit::alert-dialog.actions>
                </x-wirekit::alert-dialog>
            @endif
            {{-- WCAG 4.1.3: Save / Translate / Mark reviewed announce their result here, beside the
                 buttons they belong to, where a phone shows it. The region is always in the DOM so
                 the update is spoken: a live region inserted together with its text is not
                 announced. The text is a plain x-wirekit::text, not an x-wirekit::alert: the alert is
                 itself a role="status" region, and nesting one live region in another
                 double-announces and conflicts on politeness. --}}
            {{-- `sr-only` while empty, and only then, the pattern the consent panel carries for the
                 same reason: an empty region has no height but is still a child of the stack, so it
                 takes a gap.

                 On this region it is consistency rather than a saving: it sits after the buttons, so
                 an empty one has nothing to push. The stale region above is where it does act.
                 Treating the two live regions of one screen differently would cost the next reader
                 more than the attribute does. --}}
            <div role="status" aria-live="polite" wire:key="lc-editor-status"
                @class(['sr-only' => ($status ?? '') === ''])>
                @if ($status !== '')
                    <div wire:key="lc-editor-status-{{ $statusNonce }}"><x-wirekit::text>{{ $status }}</x-wirekit::text></div>
                @endif
            </div>
        </x-wirekit::row>
        </x-wirekit::stack>

        {{-- What a release in the chosen mode would do, shown before it happens. Bound to the
             component, so the confirmation closes it with the render it causes. --}}
        @if (($releaseModes ?? []) !== [])
            <x-wirekit::modal name="lc-editor-release" wire:model="releaseDialog" size="lg">
                <x-wirekit::modal.header>{{ __('legal-consent::ui.admin_release_dialog_title', ['document' => $documentName ?? $key]) }}</x-wirekit::modal.header>
                <x-wirekit::modal.body>
                    @if (is_array($releaseSummary ?? null))
                        <x-wirekit::stack gap="md">
                            <fieldset>
                                <legend>{{ __('legal-consent::ui.admin_release_dialog_mode') }}</legend>
                                <x-wirekit::stack gap="sm">
                                    @foreach ($releaseModes as $available)
                                        <x-wirekit::radio name="releaseMode" :value="$available" wire:model.live="releaseMode" :label="__('legal-consent::ui.admin_release_modes.'.$available.'.label')" :hint="__('legal-consent::ui.admin_release_modes.'.$available.'.consequence')" />
                                    @endforeach
                                </x-wirekit::stack>
                            </fieldset>

                            <x-wirekit::data-list layout="detail">
                                <x-wirekit::data-list.item :label="__('legal-consent::ui.admin_release_dialog_languages')" :value="implode(', ', $releaseSummary['languages'])" />
                                @if ($releaseSummary['left_out'] !== [])
                                    <x-wirekit::data-list.item :label="__('legal-consent::ui.admin_release_dialog_left_out')" :value="implode('; ', $releaseSummary['left_out'])" />
                                @endif
                                <x-wirekit::data-list.item :label="__('legal-consent::ui.admin_release_dialog_version')" :value="$releaseSummary['version']" />
                                <x-wirekit::data-list.item :label="__('legal-consent::ui.admin_release_dialog_heading')" :value="$releaseSummary['heading']" />
                                @if ($releaseSummary['sentence'] !== null)
                                    <x-wirekit::data-list.item :label="__('legal-consent::ui.admin_release_dialog_sentence')">
                                        {{ $releaseSummary['sentence'] }}
                                        @if ($releaseSummary['generic'])
                                            <x-wirekit::text size="sm">{{ __('legal-consent::ui.admin_release_dialog_generic_sentence', ['locale' => $locale]) }}</x-wirekit::text>
                                        @endif
                                    </x-wirekit::data-list.item>
                                @endif
                                <x-wirekit::data-list.item :label="__('legal-consent::ui.admin_release_dialog_dates')" :value="$releaseSummary['dates']" />
                                @if ($releaseSummary['regime'] !== null)
                                    <x-wirekit::data-list.item :label="__('legal-consent::ui.admin_release_dialog_regime')" :value="$releaseSummary['regime']" />
                                @endif
                            </x-wirekit::data-list>

                            <x-wirekit::text>{{ __('legal-consent::ui.admin_release_dialog_reviewer', ['language' => $languageName ?? $locale]) }}</x-wirekit::text>
                        </x-wirekit::stack>
                    @endif
                </x-wirekit::modal.body>
                <x-wirekit::modal.footer>
                    <x-wirekit::modal.close>
                        <x-wirekit::button intent="neutral" surface="ghost">{{ __('legal-consent::ui.cancel') }}</x-wirekit::button>
                    </x-wirekit::modal.close>
                    <x-wirekit::button wire:click="confirmRelease" loading-target="confirmRelease" :disable-on-loading="false" data-wk-dialog-primary>{{ __('legal-consent::ui.admin_release_dialog_confirm') }}</x-wirekit::button>
                </x-wirekit::modal.footer>
            </x-wirekit::modal>
        @endif

        {{-- A language never published while another holds a version in force: it goes out under
             that version and changes nothing about the contract. --}}
        @if (($translationVersion ?? null) !== null)
            <x-wirekit::heading :level="2">{{ __('legal-consent::ui.admin_translation_heading', ['language' => $languageName ?? $locale]) }}</x-wirekit::heading>
            <x-wirekit::text>{{ __('legal-consent::ui.admin_translation_explainer', ['language' => $languageName ?? $locale, 'version' => $translationVersion]) }}</x-wirekit::text>
            <div>
                <x-wirekit::button wire:click="publishTranslation" loading-target="publishTranslation" :disable-on-loading="false">{{ __('legal-consent::ui.admin_translation_submit') }}</x-wirekit::button>
            </div>
        @endif

        {{-- A text never published goes out at once, in the mode of its type: a first version has
             nothing anybody could object to, so the objection window below is not offered for it. --}}
        @if ($firstPublication ?? false)
            <x-wirekit::heading :level="2">{{ __('legal-consent::ui.admin_first_publication_heading') }}</x-wirekit::heading>
            <x-wirekit::text>{{ __('legal-consent::ui.admin_first_publication_explainer.'.($firstPublicationMode ?? 'active_reconsent')) }}</x-wirekit::text>
            <div>
                <x-wirekit::button wire:click="publishFirst" loading-target="publishFirst" :disable-on-loading="false">{{ __('legal-consent::ui.admin_first_publication_submit') }}</x-wirekit::button>
            </div>
        @endif

        {{-- Only where silence can bind: an informational page binds nobody, and the publisher
             refuses every mode for it but the silent one. A caller that passes no such value
             keeps the form, as before. --}}
        @if (($offersDeemedRelease ?? true) && ! ($firstPublication ?? false))
        {{-- Release with an objection window — the WireKit twin of the plain stub's form.
             Same placement decision and the same reason: a deemed-consent release binds people by
             their silence (§ 308 Nr. 5 BGB), so it belongs where somebody has actually read the
             text, not behind a button on an overview grid. `date-picker` and `toggle` are used
             here because WireKit owns their keyboard and screen-reader behavior; the plain stub
             uses native `type="date"` and checkboxes for the same reason in the other direction. --}}
        <x-wirekit::heading :level="2">{{ __('legal-consent::ui.admin_deemed_heading') }}</x-wirekit::heading>
        <x-wirekit::text>{{ __('legal-consent::ui.admin_deemed_explainer') }}</x-wirekit::text>

        {{-- Its own scope of the unsaved hint: the class and the two switches count as unsaved until
             the release went through. The dates and the regime are live, so their values are on the
             server already and the hint does not count them. --}}
        <x-wirekit::stack gap="sm" x-wk-unsaved.until-saved.confirm="{{ \Pushery\LegalConsent\Livewire\LegalTextEditor::UNSAVED_RELEASE }}">
            {{-- Each date starts on the first day it can take, from the component's window: a
                 window that falls short would only be refused after the press. `live`, so a moved
                 announcement moves the dates nobody set by hand while the form is open.

                 Side by side where there is room, each label, control and message on one line with
                 the others even where one carries a hint; under each other on a phone. --}}
            <x-wirekit::row gap="sm" align-fields wrap>
                <x-wirekit::date-picker wire:model.live="announceAt" name="announceAt" required :min="$window['announce'] ?? null" :label="__('legal-consent::ui.admin_deemed_announce')" />
                <x-wirekit::date-picker wire:model.live="objectionDeadline" name="objectionDeadline" required :min="$window['deadline'] ?? null" :label="__('legal-consent::ui.admin_deemed_deadline')" />
                <x-wirekit::date-picker wire:model.live="enforceAt" name="enforceAt" required :min="$window['enforce'] ?? null" :label="__('legal-consent::ui.admin_deemed_enforce')" />
            </x-wirekit::row>

            @if (($window['lead'] ?? '') !== '')
                <x-wirekit::text>{{ $window['lead'] }}</x-wirekit::text>
            @endif

            {{-- The classification, beside the dates rather than apart from them: one release
                 carries both, and without the regime and the class a release from this screen would
                 freeze a contract change with neither, and say nothing about it.

                 The regime is a select over the publisher's own list, because it refuses one it
                 does not know; the class is free text, because it takes any and the command line
                 calls it a tag. --}}
            <x-wirekit::row gap="sm" align-fields wrap>
                <x-wirekit::select wire:model.live="regime" name="regime" required :label="__('legal-consent::ui.admin_deemed_regime')" :hint="in_array($regime ?? '', \Pushery\LegalConsent\Support\LegalDocumentPublisher::REGIMES, true) ? __('legal-consent::ui.admin_regimes.'.$regime.'.description') : null">
                <option value="">{{ __('legal-consent::ui.admin_deemed_regime_none') }}</option>
                {{-- The publisher's own list, read from it rather than written out here: it
                     refuses a regime it does not know, so a second vocabulary on this screen would
                     be free to drift -- and the drift shows up as a refusal on a release somebody
                     has already scheduled.

                     Read as the constant, not through a component method. `$this` is bound only
                     while Livewire renders this view, and a published stub has to render wherever
                     an application puts it: rendered directly, `$this->regimes()` fails with
                     "Using $this when not in object context". --}}
                @foreach (\Pushery\LegalConsent\Support\LegalDocumentPublisher::REGIMES as $available)
                    <option value="{{ $available }}">{{ __('legal-consent::ui.admin_regimes.'.$available.'.name') }}</option>
                @endforeach
            </x-wirekit::select>

                <x-wirekit::input wire:model="changeClass" name="changeClass" :maxlength="\Pushery\LegalConsent\Support\LegalDocumentPublisher::CHANGE_CLASS_MAX_LENGTH" :label="__('legal-consent::ui.admin_deemed_change_class')" :hint="__('legal-consent::ui.admin_deemed_change_class_hint')" />
            </x-wirekit::row>

            <x-wirekit::toggle wire:model="offersTermination" :label="__('legal-consent::ui.admin_deemed_offers_termination')" :hint="__('legal-consent::ui.admin_deemed_offers_termination_hint')" />
            <x-wirekit::toggle wire:model="keepsUnmodified" :label="__('legal-consent::ui.admin_deemed_keeps_unmodified')" :hint="__('legal-consent::ui.admin_deemed_keeps_unmodified_hint')" />

            {{-- At its own width, with the hint of the form beside it. --}}
            <x-wirekit::row gap="sm" align="center" wrap>
                <x-wirekit::unsaved-hint />
                <x-wirekit::button wire:click="releaseDeemed" loading-target="releaseDeemed" :disable-on-loading="false">{{ __('legal-consent::ui.admin_deemed_submit') }}</x-wirekit::button>
            </x-wirekit::row>
        </x-wirekit::stack>
        @endif

        <x-wirekit::heading :level="2">{{ __('legal-consent::ui.admin_preview') }}</x-wirekit::heading>
        {{-- The already-sanitized stored bytes — a true fixpoint of what a publish will freeze. --}}
        {{-- Folded by default: on a long text the preview pushed the release form and its button
             off the screen. An accordion rather than a bare disclosure, because its bordered card and
             its trigger row are how the kit marks something that opens; a trigger line with its
             chevron at the far edge of the page did not read as one. The trigger is a heading one
             level under the `h2` above.

             The item carries a fixed id. The accordion keeps which items are open by their ids, and
             a derived id is counted per request, so a render of this component alone could number it
             differently from the full page, and a save would fold a preview the reader had opened.
             With the id fixed it stays open, and the stored text replaces the old one in view. --}}
        <x-wirekit::accordion :level="3">
            <x-wirekit::accordion.item id="lc-editor-preview" :title="__('legal-consent::ui.admin_preview_show')">
                {{-- `as="section"` for the same reason as the plain twin: a div with no role maps to
                     `generic`, and ARIA forbids naming a generic element, so the label would be
                     discarded. The label key is the one the plain twin uses rather than
                     `admin_preview`, the heading's own text, which would announce the same words as
                     the heading above.

                     Through `prose`, because a preview that does not look like its subject invites
                     somebody to "correct" spacing that was never wrong: the published page sets its
                     text as prose, and headings, lists and tables render differently without it. --}}
                <x-wirekit::stack as="section" gap="none" aria-label="{{ __('legal-consent::ui.admin_preview_label') }}">
                    <x-wirekit::prose>{!! $preview !!}</x-wirekit::prose>
                </x-wirekit::stack>
            </x-wirekit::accordion.item>
        </x-wirekit::accordion>
    </x-wirekit::stack>
</div>
