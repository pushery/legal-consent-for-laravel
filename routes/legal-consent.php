<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Pushery\LegalConsent\Http\Controllers\ConsentController;
use Pushery\LegalConsent\Http\Controllers\WithdrawConsentController;
use Pushery\LegalConsent\Support\SessionWriteThrottle;

// The one SESSION-BACKED route in the package: a form POST that withdraws a consent and redirects
// back. Opt-in, like the API, and deliberately the only thing it does.
//
// It exists because the framework-agnostic settings stub had a withdraw button and nowhere to
// send it. The stub cannot call a Livewire action (being framework-agnostic is its whole point),
// and the JSON API is off by default, sits behind the `api` middleware group — no session, no
// CSRF — and answers 204 rather than redirecting. So the button posted to `#`: it looked like a
// working control and did nothing, while the comment above it promised Art. 7(3), withdrawal as
// easy as the granting was.
//
// Withdrawal ONLY. Object and terminate are reachable through the API and the Livewire component;
// adding them here would widen a public, session-backed surface for a case no bundled view has.
//
// The path is `consent/withdraw`, not `withdraw`, so that turning BOTH ways on cannot collide with
// the API's `POST {api_prefix}/withdraw`. Two routes registered under one URI do not warn — the
// later registration silently replaces the earlier — and both default to the `legal` prefix.
if (config('legal-consent.routes.web', false)) {
    $webMiddleware = config('legal-consent.routes.web_middleware', ['web', 'auth']);
    $webPrefix = config('legal-consent.routes.web_prefix', 'legal');
    $webStack = is_array($webMiddleware) ? $webMiddleware : ['web', 'auth'];

    // The same limit the Livewire component actions apply, on the same budget — the reasoning that
    // put `api_throttle` in front of the JSON API (below) is about the ledger, not about JSON, and
    // this route reaches the same append. Listed after the configured chain rather than ahead of
    // it: the framework's middleware priority places the throttle after the session and the guard
    // anyway, and that is what makes the bucket the SUBJECT rather than the address.
    $webThrottle = SessionWriteThrottle::middleware();

    if ($webThrottle !== null) {
        $webStack[] = $webThrottle;
    }

    Route::middleware($webStack)
        ->prefix(is_string($webPrefix) ? $webPrefix : 'legal')
        ->post('consent/withdraw', WithdrawConsentController::class)
        ->name('legal-consent.web.withdraw');
}

// Way C — the headless JSON API. Opt-in: nothing is registered unless
// `legal-consent.routes.api` is true. The consumer supplies the auth middleware.
if (! config('legal-consent.routes.api', false)) {
    return;
}

$middleware = config('legal-consent.routes.api_middleware', ['api', 'auth']);
$prefix = config('legal-consent.routes.api_prefix', 'legal');
$stack = is_array($middleware) ? $middleware : ['api', 'auth'];

// The rate limit is applied here rather than left inside `api_middleware`, and the inline default
// above is the load-bearing half of it.
//
// Naming the `api` group buys no throttling: since Laravel 11 that group contains a limiter only
// when the application called `throttleApi()`, and it resolves to bare SubstituteBindings
// otherwise. Behind these four write endpoints is an append-only ledger with no de-duplication
// and no pruning by default, so an unlimited caller mints permanent rows.
//
// It is a separate setting because both ways of arriving here have to be covered: a consumer who
// replaces `api_middleware` wholesale — which the config invites, since they supply their own auth
// — would drop a throttle bundled into that list, and an application whose PUBLISHED config
// predates this key never receives it at all (`mergeConfigFrom()` is flat, and its `routes` block
// wins whole). In that second case this default is the only value that runs.
$throttle = config('legal-consent.routes.api_throttle', '60,1');

if (is_string($throttle) && trim($throttle) !== '') {
    // First in the stack, so a flood is refused before anything else does work for it, and so the
    // limiter keys on the authenticated subject where there is one (it reads the user off the
    // request, which resolves through the guard regardless of middleware order).
    array_unshift($stack, 'throttle:'.$throttle);
}

Route::middleware($stack)
    ->prefix(is_string($prefix) ? $prefix : 'legal')
    ->group(function (): void {
        Route::post('consent', [ConsentController::class, 'store'])->name('legal-consent.api.store');
        Route::post('withdraw', [ConsentController::class, 'withdraw'])->name('legal-consent.api.withdraw');
        Route::post('object', [ConsentController::class, 'object'])->name('legal-consent.api.object');
        Route::post('terminate', [ConsentController::class, 'terminate'])->name('legal-consent.api.terminate');
        Route::get('status', [ConsentController::class, 'status'])->name('legal-consent.api.status');
    });
