<?php

declare(strict_types=1);

namespace Pushery\LegalConsent;

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
use Pushery\LegalConsent\Console\DispatchDueLegalNoticesCommand;
use Pushery\LegalConsent\Console\DoctorCommand;
use Pushery\LegalConsent\Console\FlushDocumentCacheCommand;
use Pushery\LegalConsent\Console\PruneExpiredConsentRecordsCommand;
use Pushery\LegalConsent\Console\PublishDocumentCommand;
use Pushery\LegalConsent\Console\VerifyDocumentsCommand;
use Pushery\LegalConsent\Console\VerifyLedgerCommand;
use Pushery\LegalConsent\Content\LegalHtmlSanitizer;
use Pushery\LegalConsent\Content\LegalSourceRenderer;
use Pushery\LegalConsent\Content\RenderPipeline;
use Pushery\LegalConsent\Content\SourceFactory;
use Pushery\LegalConsent\Contracts\ConsentManager;
use Pushery\LegalConsent\Contracts\LegalConsentMonitor;
use Pushery\LegalConsent\Contracts\LegalTextTranslator;
use Pushery\LegalConsent\Events\LegalDocumentPublished;
use Pushery\LegalConsent\Http\Middleware\EnsureLegalConsent;
use Pushery\LegalConsent\Listeners\FlushEnforceableCacheOnPublish;
use Pushery\LegalConsent\Listeners\RecordConsentOnRegistration;
use Pushery\LegalConsent\Livewire\ConsentSettings;
use Pushery\LegalConsent\Livewire\LegalTextEditor;
use Pushery\LegalConsent\Livewire\LegalTextManager;
use Pushery\LegalConsent\Livewire\ReConsentForm;
use Pushery\LegalConsent\Support\AffectedSubjectResolver;
use Pushery\LegalConsent\Support\ConsentBanner;
use Pushery\LegalConsent\Support\ConsentGate;
use Pushery\LegalConsent\Support\DefaultConsentManager;
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

final class LegalConsentServiceProvider extends ServiceProvider
{
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
            new PublishedDocumentReader,
            // The registration checklist resolves against the SAME registry and age gate the rules
            // and the recorder use, so the displayed, validated and recorded sets cannot diverge.
            $this->documentsConfig(),
            $this->boolConfig('legal-consent.age_gate.enabled', false),
            $this->intConfig('legal-consent.age_gate.threshold', 16),
        ));

        $this->app->bind(LegalConsentMonitor::class, NullMonitor::class);

        // The package ships the SEAM and the human-review gate, never a provider: an app binds its
        // own implementation. Unbound, a Translate action fails loud rather than filing the
        // untranslated source as a translation.
        $this->app->bind(LegalTextTranslator::class, UnavailableTranslator::class);

        // scoped, not singleton: RegistrationRules memoizes its active-row lookups per request, so the
        // memo must be discarded between requests (a publish in a later request must be seen).
        $this->app->scoped(RegistrationRules::class, fn (): RegistrationRules => new RegistrationRules(
            $this->documentsConfig(),
            $this->boolConfig('legal-consent.age_gate.enabled', false),
            $this->intConfig('legal-consent.age_gate.threshold', 16),
            // Same default locale the recorder resolves against — the two must agree on the
            // fallback, or the rules could require a version the ledger will not freeze.
            $this->defaultLocale(),
        ));

        $this->app->singleton(RegistrationConsentRecorder::class, fn (): RegistrationConsentRecorder => new RegistrationConsentRecorder(
            $this->app->make(ConsentManager::class),
            $this->documentsConfig(),
            $this->defaultLocale(),
        ));

        $this->app->singleton(SourceFactory::class, fn (): SourceFactory => new SourceFactory($this->app, $this->documentsConfig(), $this->sourcesConfig()));

        $this->app->singleton(RenderPipeline::class, fn (): RenderPipeline => new RenderPipeline(new LegalHtmlSanitizer, $this->markdownConfig()));

        $this->app->singleton(LegalSourceRenderer::class, fn (): LegalSourceRenderer => new LegalSourceRenderer(
            $this->app->make(SourceFactory::class),
            $this->app->make(RenderPipeline::class),
            $this->cacheStore(),
            $this->intConfig('legal-consent.cache.ttl', 86400),
            $this->stringConfig('legal-consent.cache.prefix', 'legal:doc'),
            $this->app->make(TenantContext::class),
        ));

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

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'legal-consent');
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

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();

            $this->commands([
                FlushDocumentCacheCommand::class,
                PublishDocumentCommand::class,
                CheckDriftCommand::class,
                DispatchDueLegalNoticesCommand::class,
                CloseObjectionWindowsCommand::class,
                PruneExpiredConsentRecordsCommand::class,
                VerifyDocumentsCommand::class,
                VerifyLedgerCommand::class,
                DoctorCommand::class,
            ]);
        }
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

        $this->publishes([
            __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
        ], ['legal-consent', 'legal-consent-migrations']);

        // Optional, opt-in migrations (not auto-loaded — they touch the host `users`
        // table, so a consumer publishes them deliberately).
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
