<?php

declare(strict_types=1);

namespace Pushery\LegalConsent;

use Composer\InstalledVersions;
use Illuminate\Auth\Events\Registered;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Override;
use Pushery\LegalConsent\Console\CheckDriftCommand;
use Pushery\LegalConsent\Console\CloseObjectionWindowsCommand;
use Pushery\LegalConsent\Console\DescribeChangeCommand;
use Pushery\LegalConsent\Console\DispatchDueLegalNoticesCommand;
use Pushery\LegalConsent\Console\DoctorCommand;
use Pushery\LegalConsent\Console\FlushDocumentCacheCommand;
use Pushery\LegalConsent\Console\PruneExpiredConsentRecordsCommand;
use Pushery\LegalConsent\Console\PublishDocumentCommand;
use Pushery\LegalConsent\Console\RenotifyVersionCommand;
use Pushery\LegalConsent\Console\VerifyDocumentsCommand;
use Pushery\LegalConsent\Console\VerifyLedgerCommand;
use Pushery\LegalConsent\Content\LegalHtmlSanitizer;
use Pushery\LegalConsent\Content\LegalSourceRenderer;
use Pushery\LegalConsent\Content\RenderPipeline;
use Pushery\LegalConsent\Content\SourceFactory;
use Pushery\LegalConsent\Contracts\ConsentManager;
use Pushery\LegalConsent\Contracts\LegalConsentMonitor;
use Pushery\LegalConsent\Contracts\LegalTextTranslator;
use Pushery\LegalConsent\Contracts\ResolvesNoticeIdentity;
use Pushery\LegalConsent\Events\LegalDocumentPublished;
use Pushery\LegalConsent\Http\Middleware\EnsureLegalConsent;
use Pushery\LegalConsent\Listeners\FlushEnforceableCacheOnPublish;
use Pushery\LegalConsent\Listeners\RecordConsentOnRegistration;
use Pushery\LegalConsent\Livewire\ConsentSettings;
use Pushery\LegalConsent\Livewire\LegalTextEditor;
use Pushery\LegalConsent\Livewire\LegalTextManager;
use Pushery\LegalConsent\Livewire\ReConsentForm;
use Pushery\LegalConsent\Support\AffectedSubjectResolver;
use Pushery\LegalConsent\Support\ChangeItemsAuthor;
use Pushery\LegalConsent\Support\ChangeItemsFreezer;
use Pushery\LegalConsent\Support\ConfigNoticeIdentity;
use Pushery\LegalConsent\Support\ConsentBanner;
use Pushery\LegalConsent\Support\ConsentGate;
use Pushery\LegalConsent\Support\DefaultConsentManager;
use Pushery\LegalConsent\Support\DocumentUrlResolver;
use Pushery\LegalConsent\Support\EnforceableDocumentCache;
use Pushery\LegalConsent\Support\LegalDocumentPublisher;
use Pushery\LegalConsent\Support\LegalDocumentReleaser;
use Pushery\LegalConsent\Support\LegalDriftChecker;
use Pushery\LegalConsent\Support\NullMonitor;
use Pushery\LegalConsent\Support\PublishedDocumentReader;
use Pushery\LegalConsent\Support\RegistrationConsentRecorder;
use Pushery\LegalConsent\Support\RegistrationRules;
use Pushery\LegalConsent\Support\TenantContext;
use Pushery\LegalConsent\Support\UnavailableTranslator;
use Pushery\WireKit\WireKitServiceProvider;

final class LegalConsentServiceProvider extends ServiceProvider
{
    /**
     * The lowest `pushery/wirekit` this package's WireKit views are built and tested against —
     * the same constraint `composer.json` pins for the dev dependency, held in lockstep by a
     * test that reads both.
     *
     * It is a FLOOR for the automatic choice, not a requirement of the package: below it the
     * plain views are served instead. A Blade component tag compiles unconditionally, so serving
     * views that name a component the installed WireKit does not have turns a silent styling
     * problem into a hard exception — on the re-consent gate, at the moment a legal change lands.
     * That has happened once already (a banner rendered `<x-wirekit::countdown>`, which existed
     * only on WireKit's develop branch), which is why presence alone is not the test.
     */
    public const string WIREKIT_MINIMUM = '2.26.0';

    /**
     * Whether the bundled migrations are registered automatically. Disable with
     * self::ignoreMigrations() to publish and manage them in the host app instead.
     */
    public static bool $runsMigrations = true;

    public static function ignoreMigrations(): void
    {
        self::$runsMigrations = false;
    }

    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/legal-consent.php', 'legal-consent');

        $this->app->singleton(TenantContext::class, fn (): TenantContext => new TenantContext(
            $this->boolConfig('legal-consent.tenancy.enabled', false),
        ));

        $this->app->singleton(ConsentManager::class, fn (): DefaultConsentManager => new DefaultConsentManager(
            new ConsentGate,
            $this->defaultLocale(),
            new PublishedDocumentReader($this->defaultLocale()),
            // The registration checklist resolves against the SAME registry and age gate the rules
            // and the recorder use, so the displayed, validated and recorded sets cannot diverge.
            // That registry is the REGISTRATION one: a document carrying
            // `ask_at_registration => false` leaves all three together or none of them.
            $this->registrationDocumentsConfig(),
            $this->boolConfig('legal-consent.age_gate.enabled', false),
            $this->intConfig('legal-consent.age_gate.threshold', 16),
            new DocumentUrlResolver,
            $this->nullableStringConfig('legal-consent.double_opt_in.confirm_within'),
        ));

        $this->app->bind(LegalConsentMonitor::class, NullMonitor::class);

        // Who is DECLARING a change (§ 126b BGB). The shipped resolver reads the static config
        // block; a multi-tenant application binds its own, because one global declarant names the
        // wrong legal person in every tenant but one.
        $this->app->bind(ResolvesNoticeIdentity::class, ConfigNoticeIdentity::class);

        // The package ships the SEAM and the human-review gate, never a provider: an app binds its
        // own implementation. Unbound, a Translate action fails loud rather than filing the
        // untranslated source as a translation.
        $this->app->bind(LegalTextTranslator::class, UnavailableTranslator::class);

        // scoped, not singleton: RegistrationRules memoizes its active-row lookups per request, so the
        // memo must be discarded between requests (a publish in a later request must be seen).
        $this->app->scoped(RegistrationRules::class, fn (): RegistrationRules => new RegistrationRules(
            $this->registrationDocumentsConfig(),
            $this->boolConfig('legal-consent.age_gate.enabled', false),
            $this->intConfig('legal-consent.age_gate.threshold', 16),
            // Same default locale the recorder resolves against — the two must agree on the
            // fallback, or the rules could require a version the ledger will not freeze.
            $this->defaultLocale(),
        ));

        $this->app->singleton(RegistrationConsentRecorder::class, fn (): RegistrationConsentRecorder => new RegistrationConsentRecorder(
            $this->app->make(ConsentManager::class),
            $this->registrationDocumentsConfig(),
            $this->defaultLocale(),
            // The literal, not RegistrationConsentRecorder::WITHOUT_FORM_FIELDS_WARN.
            // The config-drift test reads these inline defaults out of the SOURCE and compares
            // them to the shipped config file, so an application whose published config predates a
            // key cannot end up behaving differently from a fresh one — and a class constant is
            // not something that reader can evaluate. It would drop out of the comparison
            // silently, which is the failure that guard exists to prevent.
            $this->stringConfig('legal-consent.registration.without_form_fields', 'warn'),
        ));

        $this->app->singleton(SourceFactory::class, fn (): SourceFactory => new SourceFactory($this->app, $this->documentsConfig(), $this->sourcesConfig()));

        // The registry goes in so the pipeline can tell an `informational` page (which binds
        // nobody, and therefore has no acceptance sentence) from a document that does ask
        // something. Without it every key would default to `contract` and an Impressum would
        // freeze the default acceptance sentence into a column nothing can ever change.
        $this->app->singleton(RenderPipeline::class, fn (): RenderPipeline => new RenderPipeline(
            new LegalHtmlSanitizer,
            $this->markdownConfig(),
            documents: $this->documentsConfig(),
        ));

        $this->app->singleton(LegalSourceRenderer::class, fn (): LegalSourceRenderer => new LegalSourceRenderer(
            $this->app->make(SourceFactory::class),
            $this->app->make(RenderPipeline::class),
            $this->cacheStore(),
            $this->intConfig('legal-consent.cache.ttl', 86400),
            $this->stringConfig('legal-consent.cache.prefix', 'legal:doc'),
            $this->app->make(TenantContext::class),
        ));

        $this->app->singleton(ChangeItemsAuthor::class, fn (): ChangeItemsAuthor => new ChangeItemsAuthor($this->app->make(TenantContext::class)));

        $this->app->singleton(ChangeItemsFreezer::class, fn (): ChangeItemsFreezer => new ChangeItemsFreezer);

        $this->app->singleton(LegalDocumentPublisher::class, fn (): LegalDocumentPublisher => new LegalDocumentPublisher(
            $this->app->make(SourceFactory::class),
            $this->app->make(RenderPipeline::class),
            $this->documentsConfig(),
        ));

        $this->app->singleton(LegalDriftChecker::class, fn (): LegalDriftChecker => new LegalDriftChecker(
            $this->app->make(SourceFactory::class),
            $this->app->make(RenderPipeline::class),
        ));

        $this->app->singleton(ConsentBanner::class, fn (): ConsentBanner => new ConsentBanner(new ConsentGate, $this->defaultLocale()));

        $this->app->singleton(EnforceableDocumentCache::class, fn (): EnforceableDocumentCache => new EnforceableDocumentCache(
            $this->cacheStore(),
            $this->app->make(TenantContext::class),
            $this->intConfig('legal-consent.cache.enforceable_ttl', 60),
        ));

        $this->app->singleton(LegalDocumentReleaser::class, fn (): LegalDocumentReleaser => new LegalDocumentReleaser(
            $this->app->make(LegalDocumentPublisher::class),
            $this->app->make(AffectedSubjectResolver::class),
        ));
    }

    public function boot(): void
    {
        $this->app->make(Router::class)->aliasMiddleware('legal.consent', EnsureLegalConsent::class);

        $this->loadViewsFrom(self::viewPaths(), 'legal-consent');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'legal-consent');
        $this->loadRoutesFrom(__DIR__.'/../routes/legal-consent.php');

        // Opt-in reactive UI: register the Livewire components only when Livewire is present,
        // so the core stays headless with no hard dependency.
        if (class_exists(Livewire::class)) {
            Livewire::component('legal-consent.consent-settings', ConsentSettings::class);
            Livewire::component('legal-consent.reconsent-form', ReConsentForm::class);
            Livewire::component('legal-consent.legal-text-manager', LegalTextManager::class);
            Livewire::component('legal-consent.legal-text-editor', LegalTextEditor::class);
        }

        if (self::$runsMigrations) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        Event::listen(LegalDocumentPublished::class, FlushEnforceableCacheOnPublish::class);

        if ((bool) config('legal-consent.registration.listen_to_registered_event', true)) {
            Event::listen(Registered::class, RecordConsentOnRegistration::class);
        }

        // A scheduled command that touches this package's tables cannot run for a consumer who
        // declined them with ignoreMigrations(). The config flag beside each registration below
        // answers whether the consumer WANTS that sweep; this one answers whether it CAN run here
        // at all, and nothing connected the two — so a consumer that declined the tables still got
        // three commands a night against relations that do not exist.
        //
        // Read at boot, outside the closure: a registration that only fails later is still a
        // registration, and with schedule monitoring it is one tracker entry per run, forever.
        if (self::$runsMigrations) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                if ((bool) config('legal-consent.schedule.dispatch_notices', true)) {
                    $schedule->command('legal-consent:dispatch-notices')
                        ->hourly()
                        // Cap the overlap lock at 2h, not the 24h default: a hung hourly run should
                        // self-clear well before the next legally time-boxed sweep, so a stuck lock
                        // cannot silence the sweep — and its heartbeat — for a whole day.
                        ->withoutOverlapping(120)
                        ->onOneServer();
                }

                if ((bool) config('legal-consent.schedule.close_objection_windows', true)) {
                    $schedule->command('legal-consent:close-objection-windows')
                        ->hourly()
                        // See dispatch-notices above: a 2h overlap cap, not the 24h default.
                        ->withoutOverlapping(120)
                        ->onOneServer();
                }

                // Opt-IN, unlike its two siblings: this sweep DELETES. An app upgrading into this
                // version must not silently start erasing records it has been accumulating — that
                // decision belongs to the consumer, once, deliberately. Daily is enough for a
                // period measured in years, and it keeps the nightly window small.
                if ((bool) config('legal-consent.schedule.prune', false)) {
                    $schedule->command('legal-consent:prune')
                        ->daily()
                        ->withoutOverlapping()
                        ->onOneServer();
                }
            });
        }

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();

            $this->commands([
                FlushDocumentCacheCommand::class,
                PublishDocumentCommand::class,
                CheckDriftCommand::class,
                DispatchDueLegalNoticesCommand::class,
                RenotifyVersionCommand::class,
                DescribeChangeCommand::class,
                CloseObjectionWindowsCommand::class,
                PruneExpiredConsentRecordsCommand::class,
                VerifyDocumentsCommand::class,
                VerifyLedgerCommand::class,
                DoctorCommand::class,
            ]);
        }
    }

    /**
     * The view paths this package registers under the `legal-consent` namespace, in lookup order.
     *
     * A single path for the plain set; two for the WireKit set, the WireKit directory FIRST. The
     * finder returns the first hint that has the file, so the WireKit variant wins for the seven
     * screens it covers and everything else (the mail shell, its theme) falls through to the
     * plain directory — which is why this is a path list rather than a swap.
     *
     * A view the consumer published into `resources/views/vendor/legal-consent` is still checked
     * BEFORE either of these: `loadViewsFrom()` registers the host's vendor path first, so
     * publishing keeps overriding both sets exactly as it did.
     *
     * @return string|list<string>
     */
    public static function viewPaths(): string|array
    {
        $views = __DIR__.'/../resources/views';

        return self::usesWireKitViews() ? [$views.'/wirekit', $views] : $views;
    }

    /**
     * Whether the WireKit-native view set is the one to serve.
     *
     * `plain` and `wirekit` pin the answer. `auto` — the default, and anything unrecognized, which
     * `legal-consent:doctor` reports separately rather than letting a typo decide silently — asks
     * whether a WireKit at or above {@see WIREKIT_MINIMUM} is installed.
     */
    public static function usesWireKitViews(): bool
    {
        $variant = config('legal-consent.ui.variant', 'auto');

        return match ($variant) {
            'wirekit' => true,
            'plain' => false,
            default => self::wireKitMeetsMinimum(),
        };
    }

    /**
     * Whether the installed `pushery/wirekit` is present and new enough to render the bundled
     * WireKit views.
     *
     * The class check comes first because it is the only one that works when the package was
     * placed on the autoloader by something other than Composer. A version Composer cannot state
     * as a release — a branch checkout, reported as `dev-…` — counts as satisfied: somebody who
     * develops against a branch has chosen it, and refusing them the themed views on a string
     * comparison that has no meaning would be arbitrary.
     */
    public static function wireKitMeetsMinimum(): bool
    {
        return class_exists(WireKitServiceProvider::class)
            && self::wireKitVersionSatisfies(self::installedWireKitVersion());
    }

    /**
     * Whether a version STRING clears the floor — the decision, separated from where the string
     * came from.
     *
     * Separated because the environment cannot be varied: this package's own suite always has one
     * WireKit installed, so a check that only ever asks Composer can never exercise the answer it
     * gives for an older one. That is the branch that matters, since getting it wrong hands a
     * consumer a hard exception on the re-consent gate.
     *
     * A version Composer cannot state as a release — a branch checkout, reported as `dev-…`, or no
     * answer at all — counts as satisfied: somebody developing against a branch has chosen it, and
     * refusing them the themed views on a string comparison with no meaning would be arbitrary.
     */
    public static function wireKitVersionSatisfies(?string $version): bool
    {
        return ! is_string($version)
            || str_starts_with($version, 'dev-')
            || version_compare(ltrim($version, 'vV'), self::WIREKIT_MINIMUM, '>=');
    }

    /**
     * What Composer says is installed, or null when it cannot say — including the case where the
     * class was put on the autoloader by something other than Composer.
     */
    private static function installedWireKitVersion(): ?string
    {
        return class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('pushery/wirekit')
            ? InstalledVersions::getPrettyVersion('pushery/wirekit')
            : null;
    }

    /**
     * Every host path below is resolved through `$this->app`, never through the global
     * `config_path()` / `database_path()` / `resource_path()` / `lang_path()` helpers. Those are
     * FOUNDATION helpers — defined only in laravel/framework's Foundation/helpers.php, which no
     * focused `illuminate/*` component provides. This package requires only `illuminate/*`, so
     * calling them would declare a dependency contract it does not hold: a fatal the moment the
     * package is consumed outside a full Laravel app. The methods are on
     * Illuminate\Contracts\Foundation\Application — the type `$this->app` already has.
     */
    private function registerPublishing(): void
    {
        // Each standard group carries the umbrella tag `legal-consent` as well, so
        // `vendor:publish --tag=legal-consent` publishes the whole normal set in one go while each
        // group stays individually addressable. The umbrella deliberately EXCLUDES the opt-in
        // migrations below (one drops a column, one backfills) and the WireKit view variant (it
        // OVERWRITES the plain stubs — publishing both at once would be self-contradictory).
        $this->publishes([
            __DIR__.'/../config/legal-consent.php' => $this->app->configPath('legal-consent.php'),
        ], ['legal-consent', 'legal-consent-config']);

        // publishes(), NOT publishesMigrations() — and that is a decision with a receipt.
        //
        // publishesMigrations() makes vendor:publish rewrite each filename's date prefix to a
        // fresh timestamp. It was adopted here on 2026-07-31 and reverted the same day, because
        // both halves of the argument for it were wrong:
        //
        //  - The premise was false. These files are named 0001_01_01_000001 upwards, which sorts
        //    AFTER a fresh Laravel application's 0001_01_01_000000_create_users_table, not before
        //    it. There was no interleaving to fix, and no shipped migration touches an app-owned
        //    table anyway.
        //  - The change actively broke the opt-in pair below. Rewriting only this set, while
        //    those keep their literal names, moves 0001_01_01_000004_backfill in FRONT of the
        //    rewritten create_legal_consents_table — so `migrate` aborts on the very table the
        //    backfill writes into. Reproduced by sorting the published set both ways.
        //
        // A second reason to leave it alone, independent of the first: the publish command tests
        // whether a file already exists under its ORIGINAL name and renames only afterwards, so a
        // re-publish never recognizes the copy it wrote last time and lays down a duplicate of
        // every migration under a fresh timestamp — and UPGRADE.md tells consumers to re-publish
        // after an upgrade.
        $this->publishes([
            __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
        ], ['legal-consent', 'legal-consent-migrations']);

        // Optional, opt-in migrations (not auto-loaded — they touch the host `users`
        // table, so a consumer publishes them deliberately). Their 000003/000004 prefixes
        // are chosen, not incidental: the backfill must run after 000002 creates
        // legal_consents, and both must run before the later schema changes.
        $this->publishes([
            __DIR__.'/../database/migrations/optional/0001_01_01_000003_drop_legal_consent_cache_from_users_table.php' => $this->app->databasePath('migrations/0001_01_01_000003_drop_legal_consent_cache_from_users_table.php'),
        ], 'legal-consent-users-cache');

        $this->publishes([
            __DIR__.'/../database/migrations/optional/0001_01_01_000004_backfill_v1_legal_acceptances.php' => $this->app->databasePath('migrations/0001_01_01_000004_backfill_v1_legal_acceptances.php'),
        ], 'legal-consent-backfill');

        $this->publishes([
            __DIR__.'/../resources/views' => $this->app->resourcePath('views/vendor/legal-consent'),
        ], ['legal-consent', 'legal-consent-views']);

        // WireKit-native variants — publishing this tag overrides the plain stubs with versions
        // built from real <x-wirekit::*> components. It covers the Livewire views too: those are
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

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/legal-consent'),
        ], ['legal-consent', 'legal-consent-lang']);

        // The change-notice mail shell and its theme, separately publishable: a consumer who wants
        // to brand the notice takes these two and nothing else. Both are inside resources/views,
        // so the umbrella view tag already carries them — this tag exists so the mail can be taken
        // over without copying the consent screens as well.
        $this->publishes([
            __DIR__.'/../resources/views/mail' => $this->app->resourcePath('views/vendor/legal-consent/mail'),
        ], 'legal-consent-mail');
    }

    /**
     * The configured document registry, defensively normalized.
     *
     * @return array<string, array<string, mixed>>
     */
    private function documentsConfig(): array
    {
        $documents = config('legal-consent.documents', []);

        if (! is_array($documents)) {
            return [];
        }

        $normalized = [];

        foreach ($documents as $key => $config) {
            if (is_string($key) && is_array($config)) {
                /** @var array<string, mixed> $config */
                $normalized[$key] = $config;
            }
        }

        return $normalized;
    }

    /**
     * The subset of the registry the REGISTRATION form covers.
     *
     * A document may be published, rendered and enforceable without belonging on a sign-up form.
     * Setting `ask_at_registration => false` removes it from all THREE sides at once — the rules,
     * the checklist and the recorder — because they are only honest while they resolve the same
     * set: a form that never showed a control must not validate one, and above all must not write
     * a ledger row claiming an acceptance nobody was asked for.
     *
     * It does NOT make the document optional. A mandatory one still gates, so a subject meets it at
     * the re-consent screen instead of at sign-up. This moves WHEN it is asked, not WHETHER.
     *
     * A publication duty that binds nobody — an Impressum (§ 5 DDG), a cookie policy — wants
     * `legal_basis => 'informational'` instead, which takes it out of the gate as well.
     *
     * @return array<string, array<string, mixed>>
     */
    private function registrationDocumentsConfig(): array
    {
        return array_filter(
            $this->documentsConfig(),
            static fn (array $config): bool => ($config['ask_at_registration'] ?? true) !== false,
        );
    }

    private function defaultLocale(): string
    {
        return $this->stringConfig('legal-consent.default_locale', 'de');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function sourcesConfig(): array
    {
        $sources = config('legal-consent.sources', []);

        if (! is_array($sources)) {
            return [];
        }

        $normalized = [];

        foreach ($sources as $key => $config) {
            if (is_string($key) && is_array($config)) {
                /** @var array<string, mixed> $config */
                $normalized[$key] = $config;
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function markdownConfig(): array
    {
        $markdown = config('legal-consent.markdown', []);

        if (! is_array($markdown)) {
            return [];
        }

        $normalized = [];

        foreach ($markdown as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    private function cacheStore(): CacheRepository
    {
        $store = config('legal-consent.cache.store');

        return Cache::store(is_string($store) ? $store : null);
    }

    private function intConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_int($value) ? $value : $default;
    }

    /**
     * A config value that is meaningfully ABSENT rather than defaulted — an unset confirmation
     * window means "no window", which is not the same statement as any string.
     */
    private function nullableStringConfig(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function stringConfig(string $key, string $default): string
    {
        $value = config($key, $default);

        return is_string($value) ? $value : $default;
    }

    private function boolConfig(string $key, bool $default): bool
    {
        return filter_var(config($key, $default), FILTER_VALIDATE_BOOL);
    }
}
