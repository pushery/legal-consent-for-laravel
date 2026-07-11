{{--
    Non-blocking grace-period banner (§ 308 Nr. 5 BGB): announces a pending material
    change and its deadline WITHOUT trapping the user. role="status" + aria-live="polite"
    so a screen reader hears it without interruption. Publishable, framework-agnostic stub.

    $pending: list from ConsentBanner::pendingFor() — ['title', 'version', 'days_left', ...].
    $consentUrl: where "review now" links to.
--}}
@if (! empty($pending))
    <div class="legal-consent-banner" role="status" aria-live="polite">
        <ul class="legal-consent-banner__list">
            @foreach ($pending as $item)
                <li>
                    <span>{{ $item['title'] }} (v{{ $item['version'] }})</span>
                    <span>{{ trans_choice('legal-consent::ui.days_left', $item['days_left'], ['count' => $item['days_left']]) }}</span>
                    <a href="{{ $consentUrl ?? '#' }}">{{ __('legal-consent::ui.review') }}</a>
                </li>
            @endforeach
        </ul>
    </div>
@endif
