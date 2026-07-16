{{--
    Non-blocking legal-change banner. Three legally distinct, non-trapping sections
    (§ 308 Nr. 5 BGB reasonable notice, WP260 active push, § 675g objection window):

      $pending       — re-consent countdown (ConsentBanner::pendingFor)
      $informational — info-only "was updated, no action required" (ConsentBanner::informationalFor)
      $deemed        — deemed-consent objection window (ConsentBanner::deemedFor)

    role="status" + aria-live="polite" so a screen reader hears it without interruption.
    Publishable, framework-agnostic stub. $consentUrl: where "review" links to.
--}}
@php
    $pending ??= [];
    $informational ??= [];
    $deemed ??= [];
@endphp

@if (! empty($pending) || ! empty($informational) || ! empty($deemed))
    <div class="legal-consent-banner" role="status" aria-live="polite">
        @if (! empty($pending))
            <ul class="legal-consent-banner__list legal-consent-banner__list--reconsent">
                @foreach ($pending as $item)
                    <li>
                        <span>{{ $item['title'] }} (v{{ $item['version'] }})</span>
                        <span>{{ trans_choice('legal-consent::ui.days_left', $item['days_left'], ['count' => $item['days_left']]) }}</span>
                        <a href="{{ $consentUrl ?? '#' }}">{{ __('legal-consent::ui.review') }}</a>
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
                        <a href="{{ $consentUrl ?? '#' }}">{{ __('legal-consent::ui.review') }}</a>
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
                        <a href="{{ $consentUrl ?? '#' }}">{{ __('legal-consent::ui.object_review') }}</a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endif
