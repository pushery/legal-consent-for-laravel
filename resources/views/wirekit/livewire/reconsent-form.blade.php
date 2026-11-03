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
                            {{-- It closes with the re-render its action causes, and says so, so an application that turns close-on-confirm on for every dialog does not ask this one for a confirm control. --}}
                            <x-wirekit::alert-dialog :name="'lc-object-'.$item['key']" :close-on-confirm="false">
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
                                    <x-wirekit::button intent="danger" wire:click="object({{ \Pushery\WireKit\Support\AlpinePayload::from($item['key']) }})" :loading-target="'object('.\Pushery\WireKit\Support\AlpinePayload::from($item['key']).')'" :disable-on-loading="false">
                                        {{ __('legal-consent::ui.object') }}
                                    </x-wirekit::button>
                                </x-wirekit::alert-dialog.actions>
                            </x-wirekit::alert-dialog>
                        @endif

                        @if ($item['terminable'])
                            {{-- It closes with the re-render its action causes, and says so, so an application that turns close-on-confirm on for every dialog does not ask this one for a confirm control. --}}
                            <x-wirekit::alert-dialog :name="'lc-terminate-'.$item['key']" :close-on-confirm="false">
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

                                    <x-wirekit::button intent="danger" wire:click="terminate({{ \Pushery\WireKit\Support\AlpinePayload::from($item['key']) }})" :loading-target="'terminate('.\Pushery\WireKit\Support\AlpinePayload::from($item['key']).')'" :disable-on-loading="false">
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
                {{-- The documents go through consent-checkboxes, the registration's own view, so what a
                     host relies on there holds here too: the title inside the wording links the full text,
                     and with `legal-consent.ui.wording_dialog` the text opens in a dialog. `wording` is the
                     snapshotted `ui_wording`, the sentence the ledger records, and renders verbatim. `field`
                     keeps the id and the name `legal_{key}`, `model` binds each box to `accept.{key}`, the
                     property ReConsentForm reads, and under that binding the view keys each entry by its
                     document, which this list needs because a submit shrinks it. No box is pre-ticked:
                     `accept` starts empty (Planet49, C-673/17). Included by the package's name, so a host's
                     published consent-checkboxes renders here as it does at registration. --}}
                @include('legal-consent::consent-checkboxes', [
                    'documents' => $pending->map(static fn ($document): array => [
                        'key' => $document->key,
                        'title' => $document->title,
                        'wording' => $document->ui_wording,
                        'url' => $urls[$document->key] ?? null,
                        'locale' => $document->locale,
                        'required' => false,
                        'field' => 'legal_'.$document->key,
                        'model' => $document->key,
                    ])->values()->all(),
                    'bind' => 'accept',
                ])

                {{-- A submit with nothing ticked, said as the error it is: WireKit's danger alert, which
                     is `role="alert"` and announces itself, between the boxes it is about and the
                     button that is pressed again. Keyed by the error nonce, so a second empty submit
                     inserts a new element and is heard again. The boxes still unticked carry the error
                     state of their own field. Read as consent-checkboxes reads its errors: a view
                     rendered outside a request has no `$errors` at all. --}}
                @php($lcIncomplete = (string) (($errors ?? null)?->first('accept') ?? ''))
                @if ($lcIncomplete !== '')
                    <x-wirekit::alert intent="danger" wire:key="lc-reconsent-incomplete-{{ $errorNonce }}">{{ $lcIncomplete }}</x-wirekit::alert>
                @endif

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
