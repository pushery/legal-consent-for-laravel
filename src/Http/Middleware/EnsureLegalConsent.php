<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Pushery\LegalConsent\Contracts\ConsentManager;
use Pushery\LegalConsent\Models\LegalDocument;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks an authenticated subject with outstanding mandatory re-consent. JSON requests
 * get a 409 `legal_consent_required` (with the document keys); browser requests are
 * redirected to the consent route. The consent route and `logout` are always allowlisted,
 * so there is no redirect loop and the subject can always leave.
 */
final readonly class EnsureLegalConsent
{
    public function __construct(private ConsentManager $consent) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $subject = $request->user();

        if (! $subject instanceof Model || $this->isAllowlisted($request)) {
            return $next($request);
        }

        $outstanding = $this->consent->outstanding($subject, app()->getLocale());

        if ($outstanding->isEmpty()) {
            return $next($request);
        }

        $keys = $outstanding->map(static fn (LegalDocument $document): string => $document->key)->values()->all();

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Legal consent is required before continuing.',
                'error' => 'legal_consent_required',
                'documents' => $keys,
            ], Response::HTTP_CONFLICT);
        }

        return redirect()->to($this->consentTarget());
    }

    private function isAllowlisted(Request $request): bool
    {
        $names = ['logout'];

        $consentName = config('legal-consent.routes.consent_name');

        if (is_string($consentName) && $consentName !== '') {
            $names[] = $consentName;
        }

        $extraRoutes = config('legal-consent.middleware.allowlist_routes');

        if (is_array($extraRoutes)) {
            $names = array_merge($names, array_values(array_filter($extraRoutes, is_string(...))));
        }

        if ($request->routeIs(...$names)) {
            return true;
        }

        return $this->matchesAllowlistedPath($request);
    }

    private function matchesAllowlistedPath(Request $request): bool
    {
        $paths = config('legal-consent.middleware.allowlist_paths');

        if (is_array($paths)) {
            foreach ($paths as $path) {
                if (is_string($path) && $request->is($path)) {
                    return true;
                }
            }
        }

        $consentPath = config('legal-consent.routes.consent_path');

        return is_string($consentPath) && $consentPath !== '' && $request->is(ltrim($consentPath, '/'));
    }

    private function consentTarget(): string
    {
        $name = config('legal-consent.routes.consent_name');

        if (is_string($name) && $name !== '' && Route::has($name)) {
            return route($name);
        }

        $path = config('legal-consent.routes.consent_path', '/legal-consent');

        return is_string($path) ? $path : '/legal-consent';
    }
}
