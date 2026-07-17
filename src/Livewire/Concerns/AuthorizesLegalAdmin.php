<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Livewire\Concerns;

use Illuminate\Support\Facades\Gate;

/**
 * Fail-closed authorization for the admin screens, without inventing a capability model.
 *
 * The package ships no roles and no admin routes — which app may edit legal texts, and who inside
 * it, is the app's question. What the package must not do is expose an ungated publish button, so
 * the ability is required: with `legal-consent.admin.ability` unset there is no way to reach these
 * screens at all. Opt IN by naming a Gate ability; there is no opt-out.
 *
 * Checked in `boot()`, not `mount()`: mount runs once, hydrate runs on every subsequent request, so
 * a mount-only check would keep serving an admin whose access was revoked mid-session for as long
 * as their tab stayed open.
 *
 * 404, never 403: an unauthorized visitor should not learn that a legal-text admin exists here.
 */
trait AuthorizesLegalAdmin
{
    public function bootAuthorizesLegalAdmin(): void
    {
        $ability = config('legal-consent.admin.ability');

        abort_unless(is_string($ability) && $ability !== '' && Gate::allows($ability), 404);
    }
}
