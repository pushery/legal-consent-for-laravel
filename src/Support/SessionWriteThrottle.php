<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one rate limit in front of the session-backed ledger writes: the web withdraw route and the
 * Livewire component actions.
 *
 * `routes.api_throttle` exists because behind the JSON API sits an append-only ledger with no
 * de-duplication and no pruning by default, so an unlimited caller mints permanent rows. That
 * reasoning is about the ledger, not about JSON. The settings screen's `grant` and `withdraw`, the
 * re-consent screen's `object` and `terminate`, and the web withdraw route all reach the same
 * `append()` — and a Livewire request is one POST with a CSRF token, as scriptable as any other.
 * Measured: three `grant` calls wrote three `granted` rows, three `withdraw` calls after them wrote
 * three `withdrawn` rows. No transition asks whether the subject's standing changes.
 *
 * ONE budget for the whole surface, however it is reached, so a subject cannot spend a fresh
 * allowance on each way in. Keyed on the subject, and kept apart from any `throttle:` the host
 * applies to the same subject elsewhere: the framework keys on the authenticated user alone, so
 * without a prefix this limit and the host's own would count each other's requests.
 *
 * The value is the same shape `routes.api_throttle` takes, because it is handed to the same
 * middleware: `'60,1'` for sixty a minute, or the name of a limiter the application registered.
 * A named limiter is passed through as it is — it carries its own key, and `ThrottleRequests`
 * takes the named path only when it receives exactly one parameter.
 */
final class SessionWriteThrottle
{
    public const string PREFIX = 'legal-consent.session-writes.';

    /**
     * The `throttle` middleware's parameters, or null when the limit is switched off.
     *
     * @return non-empty-list<string>|null
     */
    public static function parameters(): ?array
    {
        // Read with a default rather than from the config file alone, for the consumer this
        // hardening has to reach: an application whose published config predates the key declares
        // the `routes` block already, and `mergeConfigFrom()` is flat — its block wins whole, and
        // the key never arrives. The inline default is then the only value that runs.
        //
        // A LITERAL, not a constant: the suite's config-drift guard reads every inline default in
        // the shipped code and holds it against config/legal-consent.php, and it can only read what
        // is written out. A constant here would leave this pair unchecked while the suite stayed
        // green.
        $value = config('legal-consent.routes.web_throttle', '60,1');

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $parts = array_map(trim(...), explode(',', $value));

        if (count($parts) === 1 && ! is_numeric($parts[0])) {
            return [$parts[0]];
        }

        // The decay is pinned before the prefix: with `'60'` alone, `throttle:60,<prefix>` would
        // read the prefix as the minutes — a string that casts to zero, and a window that never
        // closes.
        return [$parts[0], $parts[1] ?? '1', self::PREFIX];
    }

    /** The route middleware entry, or null when the limit is switched off. */
    public static function middleware(): ?string
    {
        $parameters = self::parameters();

        return $parameters === null ? null : 'throttle:'.implode(',', $parameters);
    }

    /**
     * Apply the limit to the current request from inside a Livewire action, through the SAME
     * middleware the route runs, so both ways in land in the same buckets and answer the same 429.
     *
     * The middleware is resolved through the host's `throttle` alias rather than named directly: an
     * application that called `throttleWithRedis()` keeps its buckets in Redis, and this surface
     * must count there too. An alias that is not a throttle at all is not trusted with the job —
     * the framework's own limiter runs instead, so a custom alias cannot switch the limit off by
     * accident.
     */
    public static function enforce(Request $request): void
    {
        $parameters = self::parameters();

        if ($parameters === null) {
            return;
        }

        $alias = app(Router::class)->getMiddleware()['throttle'] ?? ThrottleRequests::class;
        $middleware = is_string($alias) ? app($alias) : null;

        if (! $middleware instanceof ThrottleRequests) {
            $middleware = app(ThrottleRequests::class);
        }

        // The pass-through response is discarded: the caller is the action itself, and its work
        // starts once this returns without having thrown.
        $middleware->handle($request, static fn (): Response => new Response, ...$parameters);
    }
}
