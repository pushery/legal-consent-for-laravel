<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Livewire\LivewireManager;
use Pushery\LegalConsent\Contracts\ConsentManager;
use Pushery\LegalConsent\Models\LegalDocument;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks an authenticated subject with outstanding mandatory re-consent. JSON requests
 * get a 409 `legal_consent_required` (with the document keys); browser requests are
 * redirected to the consent route. The consent route, `logout`, the bundled withdrawal route and
 * Livewire's own endpoints are always allowlisted, so there is no redirect loop, the subject can
 * always leave, they can always withdraw a voluntary consent (Art. 7(3)) without first accepting
 * something new (Art. 7(4)), and the bundled Livewire re-consent form can actually submit (its
 * POST goes to Livewire's update channel, whose path is resolved from the installation rather than
 * assumed).
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

        if (! $subject instanceof Model || $this->isAllowlisted($request) || ! $this->gatesSubject($subject)) {
            return $next($request);
        }

        $locale = app()->getLocale();
        $owed = $this->consent->outstanding($subject, $locale);

        // A FIRST acceptance is a different question and is off by default. `outstanding()` asks
        // whether a CHANGE is owed; a subject who never accepted anything has had no change, so
        // that read is silent about them — and an application whose sign-up carries no consent
        // checkbox then treats people as having accepted a text they were never shown. The two
        // sets are merged rather than chosen between: a subject can owe a first acceptance of one
        // document and a re-consent of another at the same time, and stopping for one while
        // ignoring the other would be arbitrary.
        if ($this->gatesFirstUse()) {
            $owed = $owed->concat($this->consent->firstAcceptance($subject, $locale))->values();
        }

        if ($owed->isEmpty()) {
            return $next($request);
        }

        $keys = $owed->map(static fn (LegalDocument $document): string => $document->key)->unique()->values()->all();

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Legal consent is required before continuing.',
                'error' => 'legal_consent_required',
                'documents' => $keys,
            ], Response::HTTP_CONFLICT);
        }

        // guest() (not to()) stashes the intercepted URL as the intended target, so an app can
        // return a deep-linked subject to where they were headed once re-consent is cleared.
        // Identical redirect otherwise; the extra session key is inert for apps that never read it.
        return redirect()->guest($this->consentTarget());
    }

    /**
     * Does this installation also gate a FIRST acceptance?
     *
     * Read strictly: anything but a literal `true` leaves it off. A gate that turns itself on
     * because a config value happened to be truthy would stop every subject of an application
     * that never asked for it, and the failure would look like an outage rather than a setting.
     */
    private function gatesFirstUse(): bool
    {
        return config('legal-consent.gate.first_use') === true;
    }

    private function isAllowlisted(Request $request): bool
    {
        // `legal-consent.web.withdraw` is always allowed, for the same reason `logout` is: it is a
        // way OUT, and the gate must never be the thing standing in it. Withdrawing a voluntary
        // consent is a right the subject holds unconditionally (Art. 7(3)); making it reachable
        // only after accepting a new mandatory version would condition one on the other, which is
        // the coupling Art. 7(4) prohibits. The name is inert when `routes.web` is off — nothing
        // matches a route that was never registered.
        $names = ['logout', 'legal-consent.web.withdraw'];

        $consentName = config('legal-consent.routes.consent_name');

        if (is_string($consentName) && $consentName !== '') {
            $names[] = $consentName;
        }

        $extraRoutes = config('legal-consent.middleware.allowlist_routes');

        // ⚠️ THE NARROWING BELOW CANNOT BE KILLED BY A TEST, AND THAT IS A STATEMENT ABOUT
        // `routeIs()`, NOT ABOUT THE NARROWING. Measured: `Request::routeIs()` is an untyped
        // variadic (`routeIs(...$patterns)`) that hands each pattern to `Route::named()`, and a
        // non-string there simply matches nothing. So dropping the `is_string` filter -- or the
        // `!== ''` guard on the name above -- changes no observable behavior today, and the
        // nightly reports both as survivors for that reason.
        //
        // They stay for the reason they were written: the accessors feeding this are `mixed`, the
        // filter is what a static analyzer reads as the narrowing, and the day `routeIs()` is
        // typed `string ...$patterns` the unfiltered form becomes a TypeError on a legal gate.
        // A guard that is redundant against today's framework contract is not a guard that is
        // wrong -- but it must be labeled, or the next reader deletes it to close a survivor.
        //
        // `array_values` is redundant for a second, independent reason: `array_merge` renumbers
        // integer keys itself. It is kept because the intent (a positional list) is the thing
        // being expressed, not the renumbering.
        if (is_array($extraRoutes)) {
            $names = array_merge($names, array_values(array_filter($extraRoutes, is_string(...))));
        }

        if ($request->routeIs(...$names)) {
            return true;
        }

        if ($this->isLivewireEndpoint($request)) {
            return true;
        }

        return $this->matchesAllowlistedPath($request);
    }

    /**
     * Livewire's own endpoints are ALWAYS allowed, exactly like `logout` and the consent route.
     *
     * The documented wiring puts this middleware on the `web` group, which contains Livewire's update
     * channel — and a Livewire request expects JSON, so the gate answered it with 409. That deadlocks
     * the one screen that can clear the gate: the bundled re-consent form is a Livewire component, so
     * ticking and submitting it POSTs to that channel and is refused. The subject could never
     * consent, and never leave.
     *
     * The endpoints are RESOLVED, not written out. Livewire 3 mounted them under a fixed `livewire/`
     * prefix; Livewire 4 derives the prefix from APP_KEY (`/livewire-<8 hex>/`) so that a scanner
     * cannot find it by name. A hard-coded pattern therefore matches nothing on Livewire 4 — and
     * writing an installation's own hash into the allowlist is worse than the bug, because it is
     * green in development and dead in production, where APP_KEY differs.
     *
     * Consequence to be honest about: a gated subject can still reach OTHER Livewire components.
     * That is Livewire's own security model, not a hole opened here — a component must authorize
     * itself (route middleware never protects a Livewire action), which is exactly what this
     * package's own admin screens do in `boot()`.
     */
    private function isLivewireEndpoint(Request $request): bool
    {
        return $request->is('livewire/*') || (class_exists(LivewireManager::class) && $this->matchesResolvedLivewireEndpoint($request));
    }

    /**
     * The prefix covers the whole family in one pattern — update, the JavaScript asset and its source
     * map, uploads, previews, per-component CSS and JS. Allowlisting only the update channel is not
     * enough: the browser fetches the script over a separate request, and a redirected script leaves
     * the re-consent screen without the JavaScript that submits it.
     *
     * The update URI is resolved on top because a host may have moved that one endpoint with
     * `Livewire::setUpdateRoute()`, which the prefix does not follow.
     */
    private function matchesResolvedLivewireEndpoint(Request $request): bool
    {
        $manager = app(LivewireManager::class);

        // Neither accessor declares a return type, so both are `mixed` to a static analyzer. The
        // filter is the narrowing — a non-string simply contributes no pattern, which is the same
        // outcome as an installation without Livewire.
        //
        // ⚠️ No test can kill the mutant that removes this filter, because no reachable Livewire
        // configuration makes either accessor return a non-string: both are read off a registered
        // route. It is narrowing against a `mixed` signature, not against an observed value, and
        // it is kept for the same reason as the one in isAllowlisted() above. The RemoveArrayItem
        // mutant on the pair IS killable and is killed -- `Livewire::setUpdateRoute()` moves the
        // update endpoint off the prefix, which is the whole reason both are consulted.
        $endpoints = array_filter([$manager->getUriPrefix(), $manager->getUpdateUri()], is_string(...));

        foreach ($endpoints as $endpoint) {
            $path = mb_trim($endpoint, '/');

            if ($path !== '' && ($request->is($path) || $request->is($path.'/*'))) {
                return true;
            }
        }

        return false;
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

    /**
     * Whether this subject is in scope of the consent gate. A null predicate gates every
     * authenticated subject (the default); a configured `fn (Model): bool` (or invokable
     * class-string) returning false lets a subject through, so an app can order the gate after its
     * own verification / onboarding gates instead of overtaking them. A misconfigured predicate
     * fails SAFE — the subject stays gated rather than slipping past a legal gate.
     */
    private function gatesSubject(Model $subject): bool
    {
        $filter = config('legal-consent.gate.subject_filter');

        if ($filter === null) {
            return true;
        }

        if (is_string($filter) && class_exists($filter)) {
            $filter = app($filter);
        }

        return is_callable($filter) ? (bool) $filter($subject) : true;
    }
}
