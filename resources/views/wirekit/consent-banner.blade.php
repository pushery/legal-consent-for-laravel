{{--
    WireKit-flavored variant of the non-blocking grace-period banner (§ 308 Nr. 5 BGB).
    Publish with `--tag=legal-consent-wirekit` and adapt to a WireKit callout/badge. WireKit
    spacing tokens; role="status" + aria-live keep it non-intrusive.

    $pending: ['title','version','days_left']; $consentUrl: review link.
--}}
@if (! empty($pending))
    {{-- Swap the wrapper for <wk:callout variant="warning"> in a WireKit app. --}}
    <div class="wk-callout wk-callout-warning legal-consent-banner" role="status" aria-live="polite">
        <ul class="wk-stack wk-gap-xs legal-consent-banner__list">
            @foreach ($pending as $item)
                <li class="wk-row wk-gap-sm wk-items-center">
                    <span>{{ $item['title'] }} (v{{ $item['version'] }})</span>
                    <span class="wk-badge">{{ trans_choice('legal-consent::ui.days_left', $item['days_left'], ['count' => $item['days_left']]) }}</span>
                    <a class="wk-link" href="{{ $consentUrl ?? '#' }}">{{ __('legal-consent::ui.review') }}</a>
                </li>
            @endforeach
        </ul>
    </div>
@endif
