{{--
    WireKit-native variant of the change banners. Publish with `--tag=legal-consent-wirekit`.

    Built from real `x-wirekit::*` components — needs `pushery/wirekit` >= 2.13 (the `countdown`
    component landed in 2.13.0) and `@wirekitScripts` in the layout for the live countdown. The
    package's own test suite renders these views against the installed WireKit and fails on any
    component the release lacks, so this never reaches for one the app cannot resolve.

    Three legally distinct banners, never merged into one: an ActiveReconsent countdown (act, or
    access stops), an InfoPush heads-up (nothing to do), and a DeemedConsent objection window
    (silence binds unless you object). Each is a named `region` landmark — an aria-live region
    present at page load never announces, so the banner is made discoverable via landmark
    navigation instead of a live region that would do nothing.

    $pending:       list{key, title, version, enforce_from, days_left}  — re-consent countdown
    $informational: list{key, title, version, effective_from, offers_termination} — info-only
    $deemed:        list{key, title, version, objection_deadline, enforce_from, days_left}
    $consentUrl:    where the subject acts.

    The countdown carries its OWN `role="timer"` (an implicit aria-live=off), so its ticking value
    is never announced every second — it is safe inside the region landmark above.
--}}
@php
    // A legal deadline is an ABSOLUTE instant. The countdown is driven by that instant, never a
    // duration: a duration drifts the moment the tab sleeps or the page is cached, and a drifting
    // § 308 Nr. 5 deadline misstates the subject's rights.
    $lcWarn = 7 * 24 * 60 * 60; // the final week reads as urgent

    // No target, no call to action — identical to the plain stub, and to the decision the
    // withdrawal control already carries in consent-settings. A link falling back to `#` reads as
    // an actionable item with a fully worded promise and moves focus to the top of the document
    // when activated. The countdown keeps stating what changes and by when.
    $consentUrl = ($consentUrl ?? '') !== '' ? $consentUrl : null;
@endphp

@if (! empty($pending))
    <x-wirekit::callout intent="warning" role="region" :aria-label="__('legal-consent::ui.banner_label')" class="legal-consent-banner">
        <x-wirekit::stack gap="sm">
            @foreach ($pending as $item)
                <x-wirekit::stack :gap="'xs'" class="legal-consent-banner__item">
                    <x-wirekit::text>
                        {{ $item['title'] }} <x-wirekit::badge intent="neutral" size="sm">v{{ $item['version'] }}</x-wirekit::badge>
                    </x-wirekit::text>

                    @if (! empty($item['enforce_from']))
                        <x-wirekit::countdown
                            :until="$item['enforce_from']"
                            :warn-threshold="$lcWarn"
                            :expired-text="__('legal-consent::ui.enforced_now')"
                        />
                    @endif

                    @if ($consentUrl !== null)
                        <x-wirekit::link :href="$consentUrl">{{ __('legal-consent::ui.review') }}</x-wirekit::link>
                    @endif
                </x-wirekit::stack>
            @endforeach
        </x-wirekit::stack>
    </x-wirekit::callout>
@endif

@if (! empty($informational))
    {{-- Info-only: announced, never gated. `intent="info"` and no CTA — the wording must not
         imply an action the subject does not have to take (WP260 rev.01 Rz. 30-31). --}}
    <x-wirekit::callout intent="info" role="region" :aria-label="__('legal-consent::ui.banner_label')" class="legal-consent-banner legal-consent-banner--info">
        <x-wirekit::stack gap="sm">
            @foreach ($informational as $item)
                <x-wirekit::text>
                    {{ $item['title'] }}
                    <x-wirekit::badge intent="neutral" size="sm">v{{ $item['version'] }}</x-wirekit::badge>
                    — {{ __('legal-consent::ui.updated_note') }}
                    @if ($consentUrl !== null)
                        <x-wirekit::link :href="$consentUrl">{{ __('legal-consent::ui.review') }}</x-wirekit::link>
                    @endif
                </x-wirekit::text>
            @endforeach
        </x-wirekit::stack>
    </x-wirekit::callout>
@endif

@if (! empty($deemed))
    {{-- Deemed consent: the objection window. The countdown is the § 308 Nr. 5 lit. a period —
         the subject must be able to see how long they still have to object. --}}
    <x-wirekit::callout intent="warning" role="region" :aria-label="__('legal-consent::ui.banner_label')" class="legal-consent-banner legal-consent-banner--deemed">
        <x-wirekit::stack gap="sm">
            @foreach ($deemed as $item)
                <x-wirekit::stack :gap="'xs'">
                    <x-wirekit::text>
                        {{ $item['title'] }} <x-wirekit::badge intent="neutral" size="sm">v{{ $item['version'] }}</x-wirekit::badge>
                    </x-wirekit::text>

                    @if (! empty($item['objection_deadline']))
                        <x-wirekit::countdown
                            :until="$item['objection_deadline']"
                            :warn-threshold="$lcWarn"
                            :expired-text="__('legal-consent::ui.objection_closed')"
                        />
                    @endif

                    @if ($consentUrl !== null)
                        <x-wirekit::link :href="$consentUrl">{{ __('legal-consent::ui.object_review') }}</x-wirekit::link>
                    @endif
                </x-wirekit::stack>
            @endforeach
        </x-wirekit::stack>
    </x-wirekit::callout>
@endif
