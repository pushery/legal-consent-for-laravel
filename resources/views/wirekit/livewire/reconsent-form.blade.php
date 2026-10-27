{{--
    WireKit-native view for the ReConsentForm Livewire component. Publish with
    `--tag=legal-consent-wirekit`; it overrides `legal-consent::livewire.reconsent-form`.

    Single root element (Livewire requires it). Needs `pushery/wirekit` in the host app.

    Never pre-checked (CJEU C-673/17 Planet49): `wire:model` binds to $accept[key], which starts
    empty. `$document->ui_wording` is the snapshotted wording — it is what gets recorded as what
    the subject agreed to, so it renders verbatim and is never re-phrased here.
--}}
<div class="legal-consent-reconsent">
    {{-- WCAG 4.1.3 + 2.4.3: on submit the outstanding list can empty and the whole <form> is replaced
         by the "all current" callout — inserted with its text, so not announced, and the focused submit
         button vanishes. This always-present region carries the confirmation and takes focus so it does
         not drop to <body>. The text is a plain x-wirekit::text, not a nested alert/callout (those are
         themselves role="status" — nesting double-announces). Focus via x-effect, not x-init. --}}
    <div role="status" aria-live="polite" tabindex="-1" wire:key="lc-reconsent-status"
        x-effect="$wire.statusNonce > 0 && $el.focus()">
        @if (($status ?? '') !== '')
            <div wire:key="lc-reconsent-status-{{ $statusNonce }}"><x-wirekit::text>{{ $status }}</x-wirekit::text></div>
        @endif
    </div>

    {{-- The deemed-consent changes that have not taken effect yet, as in the plain view: the notice
         and the banner send the subject here, so the objection has to be possible here, and after the
         window the termination the notice promises until the change takes effect stays on offer
         alone. Each row repeats the notice's own sentences, the termination appears only where the
         notice named it, and each control only where this mount allows it. Both write an irreversible
         row, so each asks once in an alert-dialog, as the withdrawal does, never in the native confirm. --}}
    @php($deemed ??= [])
    @if ($deemed !== [])
        <x-wirekit::stack gap="sm" as="section" aria-labelledby="lc-objections-heading" class="legal-consent-objections" wire:key="lc-objections">
            <x-wirekit::heading :level="2" id="lc-objections-heading">{{ __('legal-consent::ui.upcoming_changes_heading') }}</x-wirekit::heading>

            @foreach ($deemed as $item)
                @php($lang = \Pushery\LegalConsent\Support\ContentLanguage::differingFrom($item['locale']))
                <x-wirekit::stack gap="xs" wire:key="lc-deemed-{{ $item['key'] }}">
                    <x-wirekit::text>
                        @if ($item['url'] !== null)
                            <x-wirekit::link :href="$item['url']" :hreflang="$lang" :lang="$lang" external>{{ $item['title'] }}</x-wirekit::link>
                        @else
                            <span @if ($lang !== null) lang="{{ $lang }}" @endif>{{ $item['title'] }}</span>
                        @endif
                        <x-wirekit::badge intent="neutral" size="sm">v{{ $item['version'] }}</x-wirekit::badge>
                    </x-wirekit::text>

                    @if ($item['objection_date'] !== null)
                        <x-wirekit::text>{{ __('legal-consent::notifications.deemed.warning', ['deadline' => $item['objection_date']]) }}</x-wirekit::text>
                    @endif

                    @if ($item['terminable'] && $item['effective_date'] !== null)
                        <x-wirekit::text>{{ __('legal-consent::notifications.deemed.termination', ['effective' => $item['effective_date']]) }}</x-wirekit::text>
                    @endif

                    <x-wirekit::row wrap gap="sm">
                        @if ($item['objectable'])
                            <x-wirekit::alert-dialog :name="'lc-object-'.$item['key']">
                                <x-slot:trigger>
                                    <x-wirekit::button intent="danger" surface="outline" :aria-label="__('legal-consent::ui.object_for', ['title' => $item['title']])">
                                        {{ __('legal-consent::ui.object') }}
                                    </x-wirekit::button>
                                </x-slot:trigger>

                                <x-wirekit::alert-dialog.title>
                                    {{ __('legal-consent::ui.object_confirm_title') }}
                                </x-wirekit::alert-dialog.title>

                                <x-wirekit::alert-dialog.description>
                                    {{ __('legal-consent::ui.object_confirm_body', ['title' => $item['title']]) }}
                                </x-wirekit::alert-dialog.description>

                                <x-wirekit::alert-dialog.actions>
                                    <x-wirekit::alert-dialog.cancel>
                                        {{ __('legal-consent::ui.cancel') }}
                                    </x-wirekit::alert-dialog.cancel>

                                    {{-- An echo, not `@js()`, and the busy state bound with its argument:
                                         see the withdraw button of the settings view for why. --}}
                                    <x-wirekit::button intent="danger" wire:click="object({{ \Illuminate\Support\Js::from($item['key']) }})" :loading-target="'object('.\Illuminate\Support\Js::from($item['key']).')'" :disable-on-loading="false">
                                        {{ __('legal-consent::ui.object') }}
                                    </x-wirekit::button>
                                </x-wirekit::alert-dialog.actions>
                            </x-wirekit::alert-dialog>
                        @endif

                        @if ($item['terminable'])
                            <x-wirekit::alert-dialog :name="'lc-terminate-'.$item['key']">
                                <x-slot:trigger>
                                    <x-wirekit::button intent="danger" surface="outline" :aria-label="__('legal-consent::ui.terminate_for', ['title' => $item['title']])">
                                        {{ __('legal-consent::ui.terminate') }}
                                    </x-wirekit::button>
                                </x-slot:trigger>

                                <x-wirekit::alert-dialog.title>
                                    {{ __('legal-consent::ui.terminate_confirm_title') }}
                                </x-wirekit::alert-dialog.title>

                                <x-wirekit::alert-dialog.description>
                                    {{ __('legal-consent::ui.terminate_confirm_body', ['title' => $item['title']]) }}
                                </x-wirekit::alert-dialog.description>

                                <x-wirekit::alert-dialog.actions>
                                    <x-wirekit::alert-dialog.cancel>
                                        {{ __('legal-consent::ui.cancel') }}
                                    </x-wirekit::alert-dialog.cancel>

                                    <x-wirekit::button intent="danger" wire:click="terminate({{ \Illuminate\Support\Js::from($item['key']) }})" :loading-target="'terminate('.\Illuminate\Support\Js::from($item['key']).')'" :disable-on-loading="false">
                                        {{ __('legal-consent::ui.terminate') }}
                                    </x-wirekit::button>
                                </x-wirekit::alert-dialog.actions>
                            </x-wirekit::alert-dialog>
                        @endif
                    </x-wirekit::row>
                </x-wirekit::stack>
            @endforeach
        </x-wirekit::stack>
    @endif

    @if ($pending->isEmpty())
        {{-- "Nothing to do" would contradict an open objection window above it. --}}
        @if ($deemed === [])
            <x-wirekit::callout intent="success" icon>
                {{ __('legal-consent::ui.all_current') }}
            </x-wirekit::callout>
        @endif
    @else
        <form wire:submit="submit">
            <x-wirekit::stack gap="md">
                @foreach ($pending as $document)
                    {{-- `lang` on the field, because the wording reaches the checkbox as a prop and
                         everything inside is that document's text anyway. See the plain gate. --}}
                    @php($lang = \Pushery\LegalConsent\Support\ContentLanguage::differingFrom($document->locale))
                    {{-- `wire:key` for the same reason as the plain gate: this loop shrinks, so a
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

                {{-- This button has no Livewire action of its own: the <form> carries `wire:submit`. On
                     such a button x-wirekit::button reads `loading`, which `loading-target` implies, as a
                     declarative state that nothing on the tag can switch off. It would render a spinner
                     and `aria-busy="true"` from the first paint, and, on every WireKit this package
                     supports, a `disabled` submit on the one screen a subject cannot leave without
                     it.

                     So the wait is reported the way the plain twin reports it: `aria-busy` through the
                     attribute bag, which x-wirekit::button renders as written, only while `submit` is in
                     flight, and the visible label beside it. `disabled` stays out on purpose, because it
                     blurs the control the subject just activated and focus falls to <body> for the whole
                     request (WCAG 2.4.3). --}}
                <x-wirekit::button type="submit" intent="primary" wire:loading.attr="aria-busy" wire:target="submit">
                    {{ __('legal-consent::ui.submit') }}
                </x-wirekit::button>
                <x-wirekit::text wire:loading wire:target="submit" class="legal-consent-busy">{{ __('legal-consent::ui.working') }}</x-wirekit::text>
            </x-wirekit::stack>
        </form>
    @endif
</div>
