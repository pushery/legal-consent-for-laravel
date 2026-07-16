<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Pushery\LegalConsent\Http\Controllers\ConsentController;

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
