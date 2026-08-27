{{--
    Non-blocking legal-change banner. Three legally distinct, non-trapping sections
    (§ 308 Nr. 5 BGB reasonable notice, WP260 active push, § 675g objection window):

      $pending       — re-consent countdown (ConsentBanner::pendingFor)
      $informational — info-only "was updated, no action required" (ConsentBanner::informationalFor)
      $deemed        — deemed-consent objection window (ConsentBanner::deemedFor)

    a named `region` landmark (an aria-live region present at page load never announces,
    so it would be inert here; the landmark makes the banner discoverable in landmark navigation).
    Publishable, framework-agnostic stub. $consentUrl: where "review" links to.
--}}
@php
    $pending ??= [];
    $informational ??= [];
    $deemed ??= [];

    // No target, no call to action — the same decision the withdrawal control in
    // consent-settings already carries ("a form with no action is not a control, it is the
    // appearance of one"). A link falling back to `#` is that one element over: it stands in a
    // screen reader's link list with the full promise of its text, and activating it moves focus
    // to the top of the document and does nothing else, leaving the banner above the focus
    // position. The countdown still states what changes and by when; only the dead control goes.
    $consentUrl = ($consentUrl ?? '') !== '' ? $consentUrl : null;
@endphp

@if (! empty($pending) || ! empty($informational) || ! empty($deemed))
    <div class="legal-consent-banner" role="region" aria-label="{{ __('legal-consent::ui.banner_label') }}">
        @if (! empty($pending))
            <ul class="legal-consent-banner__list legal-consent-banner__list--reconsent">
                @foreach ($pending as $item)
                    <li>
                        <span>{{ $item['title'] }} (v{{ $item['version'] }})</span>
                        <span>{{ trans_choice('legal-consent::ui.days_left', $item['days_left'], ['count' => $item['days_left']]) }}</span>
                        @if ($consentUrl !== null)
                            <a href="{{ $consentUrl }}">{{ __('legal-consent::ui.review') }}</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if (! empty($informational))
            <ul class="legal-consent-banner__list legal-consent-banner__list--info">
                @foreach ($informational as $item)
                    <li>
                        <span>{{ $item['title'] }} (v{{ $item['version'] }})</span>
                        <span>{{ __('legal-consent::ui.updated_note') }}</span>
                        @if ($consentUrl !== null)
                            <a href="{{ $consentUrl }}">{{ __('legal-consent::ui.review') }}</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if (! empty($deemed))
            <ul class="legal-consent-banner__list legal-consent-banner__list--deemed">
                @foreach ($deemed as $item)
                    <li>
                        <span>{{ $item['title'] }} (v{{ $item['version'] }})</span>
                        <span>{{ trans_choice('legal-consent::ui.days_left', $item['days_left'], ['count' => $item['days_left']]) }}</span>
                        @if ($consentUrl !== null)
                            <a href="{{ $consentUrl }}">{{ __('legal-consent::ui.object_review') }}</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endif
