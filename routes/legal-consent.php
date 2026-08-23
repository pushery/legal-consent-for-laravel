<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Pushery\LegalConsent\Http\Controllers\ConsentController;
use Pushery\LegalConsent\Http\Controllers\WithdrawConsentController;

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

    Route::middleware(is_array($webMiddleware) ? $webMiddleware : ['web', 'auth'])
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

Route::middleware(is_array($middleware) ? $middleware : ['api', 'auth'])
    ->prefix(is_string($prefix) ? $prefix : 'legal')
    ->group(function (): void {
        Route::post('consent', [ConsentController::class, 'store'])->name('legal-consent.api.store');
        Route::post('withdraw', [ConsentController::class, 'withdraw'])->name('legal-consent.api.withdraw');
        Route::post('object', [ConsentController::class, 'object'])->name('legal-consent.api.object');
        Route::post('terminate', [ConsentController::class, 'terminate'])->name('legal-consent.api.terminate');
        Route::get('status', [ConsentController::class, 'status'])->name('legal-consent.api.status');
    });
