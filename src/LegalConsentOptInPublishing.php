<?php

declare(strict_types=1);

namespace Pushery\LegalConsent;

use Illuminate\Support\ServiceProvider;

/**
 * The publish groups a consumer has to ask for BY NAME, held on a provider of their own.
 *
 * ⚠️ THIS CLASS EXISTS BECAUSE `--provider` IGNORES TAGS, and that is a framework fact rather than
 * an oversight anyone could have coded around in place. `ServiceProvider::publishes()` merges every
 * path into `static::$publishes[static::class]` regardless of the tag it was given, and
 * `pathsForProviderOrGroup()` hands back that whole map when a provider is named without a group.
 * `php artisan vendor:publish --provider="…\LegalConsentServiceProvider"` — offered as a first-class
 * interactive choice, and the obvious thing to type — therefore published everything the tag design
 * deliberately withholds.
 *
 * What that withholds is not cosmetic:
 *
 *  - `legal-consent-backfill` READS the host `users` table and writes what it finds into
 *    `legal_consents`. Its no-op guard does not help the people it needs to: an installation
 *    WITHOUT `terms_accepted_at` is exactly the one the migration skips, and one WITH those
 *    columns — a v1 app — is the case the scenario is about.
 *  - `legal-consent-users-cache` DROPS columns from `users`.
 *  - `legal-consent-wirekit` maps component-based twins onto the plain stubs' own destinations,
 *    which is wrong for an application that does not have the component library installed. (It
 *    needs `--force` to actually replace an existing file — see the note at the group itself.)
 *
 * Groups stay global, so every documented `--tag=…` keeps working untouched: `pathsForGroup()`
 * searches `static::$publishGroups`, which is keyed by tag and not by provider. Only the
 * provider-scoped shortcut changes, and only by no longer reaching past the choice a consumer made.
 *
 * Registered from {@see LegalConsentServiceProvider::registerPublishing()} rather than declared in
 * `composer.json`. Package discovery would work equally well and would put a second class in front
 * of every consumer who lists providers by hand — this one is an implementation detail of where a
 * publish map lives, and naming it in the manifest would suggest it is something to reason about.
 *
 * **There is deliberately no `runningInConsole()` guard in `boot()` below**, and its absence is
 * measured rather than forgotten. That single registration site sits INSIDE the main provider's own
 * `if ($this->app->runningInConsole())`, and the manifest names only the main provider, so nothing
 * can register this class outside a console run. A guard here would be a branch no run can enter —
 * which is precisely what the coverage floor reported when one was written: not a missing test, but
 * a line that cannot be reached. If the registration ever moves out from under that gate, put the
 * guard back with it; until then the console decision stays in one place.
 */
final class LegalConsentOptInPublishing extends ServiceProvider
{
    public function boot(): void
    {
        // Optional, opt-in migrations (not auto-loaded — they touch the host `users` table, so a
        // consumer publishes them deliberately). Their 000003/000004 prefixes are chosen, not
        // incidental: the backfill must run after 000002 creates legal_consents, and both must run
        // before the later schema changes.
        $this->publishes([
            __DIR__.'/../database/migrations/optional/0001_01_01_000003_drop_legal_consent_cache_from_users_table.php' => $this->app->databasePath('migrations/0001_01_01_000003_drop_legal_consent_cache_from_users_table.php'),
        ], 'legal-consent-users-cache');

        $this->publishes([
            __DIR__.'/../database/migrations/optional/0001_01_01_000004_backfill_v1_legal_acceptances.php' => $this->app->databasePath('migrations/0001_01_01_000004_backfill_v1_legal_acceptances.php'),
        ], 'legal-consent-backfill');

        // WireKit-native variants — this tag maps component-built views onto the SAME destinations
        // the plain stubs use. ⚠️ It does not overwrite them on its own: `vendor:publish` skips a
        // target that already exists, so on an installation that has published views before, this
        // tag needs `--force` or it is a silent no-op. Whichever tag ran FIRST wins otherwise, not
        // whichever is more specific. It covers the Livewire views too: those are
        // what the ConsentSettings/ReConsentForm components actually render, so a tag that skipped
        // them would leave a WireKit+Livewire app with unstyled reactive screens while reporting
        // that it had themed the UI.
        $this->publishes([
            __DIR__.'/../resources/views/wirekit/consent-checkboxes.blade.php' => $this->app->resourcePath('views/vendor/legal-consent/consent-checkboxes.blade.php'),
            __DIR__.'/../resources/views/wirekit/consent-banner.blade.php' => $this->app->resourcePath('views/vendor/legal-consent/consent-banner.blade.php'),
            __DIR__.'/../resources/views/wirekit/consent-settings.blade.php' => $this->app->resourcePath('views/vendor/legal-consent/consent-settings.blade.php'),
            __DIR__.'/../resources/views/wirekit/livewire/consent-settings.blade.php' => $this->app->resourcePath('views/vendor/legal-consent/livewire/consent-settings.blade.php'),
            __DIR__.'/../resources/views/wirekit/livewire/reconsent-form.blade.php' => $this->app->resourcePath('views/vendor/legal-consent/livewire/reconsent-form.blade.php'),
            __DIR__.'/../resources/views/wirekit/livewire/legal-text-manager.blade.php' => $this->app->resourcePath('views/vendor/legal-consent/livewire/legal-text-manager.blade.php'),
            __DIR__.'/../resources/views/wirekit/livewire/legal-text-editor.blade.php' => $this->app->resourcePath('views/vendor/legal-consent/livewire/legal-text-editor.blade.php'),
        ], 'legal-consent-wirekit');
    }
}
