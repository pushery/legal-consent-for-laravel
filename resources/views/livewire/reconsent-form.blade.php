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
            <ul role="list">
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
                            <button type="button" aria-label="{{ __('legal-consent::ui.object_for', ['title' => $item['title']]) }}" wire:click="object({{ json_encode($item['key'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }})" wire:confirm="{{ __('legal-consent::ui.object_confirm_body', ['title' => $item['title']]) }}" wire:loading.attr="aria-busy" wire:target="object({{ json_encode($item['key'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }})">
                                {{ __('legal-consent::ui.object') }}
                            </button>
                        @endif
                        @if ($item['terminable'])
                            <button type="button" aria-label="{{ __('legal-consent::ui.terminate_for', ['title' => $item['title']]) }}" wire:click="terminate({{ json_encode($item['key'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }})" wire:confirm="{{ __('legal-consent::ui.terminate_confirm_body', ['title' => $item['title']]) }}" wire:loading.attr="aria-busy" wire:target="terminate({{ json_encode($item['key'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }})">
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
            {{-- The documents go through consent-checkboxes, the registration's own view, so what a
                 host relies on there holds here too: the title inside the wording links the full text
                 (the WireKit views open it in a dialog where `legal-consent.ui.wording_dialog` is on).
                 `wording` is the snapshotted `ui_wording`, the sentence the ledger records, and renders
                 verbatim. `field` keeps the id and the name `legal_{key}`, and `model` binds each box to
                 `accept.{key}`, the property ReConsentForm reads. Under that binding the view keys each
                 entry by its document, which this list needs because a submit shrinks it. No box is
                 pre-ticked: `accept` starts empty (Planet49, C-673/17). Included by the package's name,
                 so a host's published consent-checkboxes renders here as it does at registration. --}}
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

            {{-- A submit with nothing ticked, as an error between the boxes and the button, announced
                 on its own and keyed by the error nonce so a second one is heard again. The boxes
                 still unticked carry the error of their own field. Read as consent-checkboxes reads
                 its errors: a view rendered outside a request has no `$errors` at all. --}}
            @php($lcIncomplete = (string) (($errors ?? null)?->first('accept') ?? ''))
            @if ($lcIncomplete !== '')
                <p role="alert" class="legal-consent-error" wire:key="lc-reconsent-incomplete-{{ $errorNonce }}">{{ $lcIncomplete }}</p>
            @endif

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
