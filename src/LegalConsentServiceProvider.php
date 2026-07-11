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
use Pushery\LegalConsent\Console\DispatchDueLegalNoticesCommand;
use Pushery\LegalConsent\Console\FlushDocumentCacheCommand;
use Pushery\LegalConsent\Console\PruneExpiredConsentRecordsCommand;
use Pushery\LegalConsent\Console\PublishDocumentCommand;
use Pushery\LegalConsent\Console\VerifyLedgerCommand;
use Pushery\LegalConsent\Content\LegalDocumentManager;
use Pushery\LegalConsent\Content\LegalHtmlSanitizer;
use Pushery\LegalConsent\Content\RenderPipeline;
use Pushery\LegalConsent\Content\SourceFactory;
use Pushery\LegalConsent\Contracts\ConsentManager;
use Pushery\LegalConsent\Contracts\LegalConsentMonitor;
use Pushery\LegalConsent\Http\Middleware\EnsureLegalConsent;
use Pushery\LegalConsent\Listeners\RecordConsentOnRegistration;
use Pushery\LegalConsent\Livewire\ConsentSettings;
use Pushery\LegalConsent\Livewire\ReConsentForm;
use Pushery\LegalConsent\Support\ConsentBanner;
use Pushery\LegalConsent\Support\ConsentGate;
use Pushery\LegalConsent\Support\DefaultConsentManager;
use Pushery\LegalConsent\Support\LegalDocumentPublisher;
use Pushery\LegalConsent\Support\LegalDriftChecker;
use Pushery\LegalConsent\Support\NullMonitor;
use Pushery\LegalConsent\Support\RegistrationConsentRecorder;
use Pushery\LegalConsent\Support\RegistrationRules;
use Pushery\LegalConsent\Support\TenantContext;

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
            $this->stringConfig('legal-consent.tenancy.column', 'tenant_id'),
        ));

        $this->app->singleton(ConsentManager::class, fn (): DefaultConsentManager => new DefaultConsentManager(new ConsentGate, $this->defaultLocale()));

        $this->app->bind(LegalConsentMonitor::class, NullMonitor::class);

        $this->app->singleton(RegistrationRules::class, fn (): RegistrationRules => new RegistrationRules(
            $this->documentsConfig(),
            $this->boolConfig('legal-consent.age_gate.enabled', false),
            $this->intConfig('legal-consent.age_gate.threshold', 16),
        ));

        $this->app->singleton(RegistrationConsentRecorder::class, fn (): RegistrationConsentRecorder => new RegistrationConsentRecorder(
            $this->app->make(ConsentManager::class),
            $this->documentsConfig(),
            $this->defaultLocale(),
        ));

        $this->app->singleton(SourceFactory::class, fn (): SourceFactory => new SourceFactory($this->app, $this->documentsConfig(), $this->sourcesConfig()));

        $this->app->singleton(RenderPipeline::class, fn (): RenderPipeline => new RenderPipeline(new LegalHtmlSanitizer, $this->markdownConfig()));

        $this->app->singleton(LegalDocumentManager::class, fn (): LegalDocumentManager => new LegalDocumentManager(
            $this->app->make(SourceFactory::class),
            $this->app->make(RenderPipeline::class),
            $this->cacheStore(),
            $this->intConfig('legal-consent.cache.ttl', 86400),
            $this->stringConfig('legal-consent.cache.prefix', 'legal:doc'),
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
        }

        if (self::$runsMigrations) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        if ((bool) config('legal-consent.registration.listen_to_registered_event', true)) {
            Event::listen(Registered::class, RecordConsentOnRegistration::class);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (! (bool) config('legal-consent.schedule.dispatch_notices', true)) {
                return;
            }

            $schedule->command('legal-consent:dispatch-notices')
                ->hourly()
                ->withoutOverlapping()
                ->onOneServer();
        });

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();

            $this->commands([
                FlushDocumentCacheCommand::class,
                PublishDocumentCommand::class,
                CheckDriftCommand::class,
                DispatchDueLegalNoticesCommand::class,
                PruneExpiredConsentRecordsCommand::class,
                VerifyLedgerCommand::class,
            ]);
        }
    }

    private function registerPublishing(): void
    {
        $this->publishes([
            __DIR__.'/../config/legal-consent.php' => config_path('legal-consent.php'),
        ], 'legal-consent-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'legal-consent-migrations');

        // Optional, opt-in migrations (not auto-loaded — they touch the host `users`
        // table, so a consumer publishes them deliberately).
        $this->publishes([
            __DIR__.'/../database/migrations/optional/0001_01_01_000003_add_legal_consent_cache_to_users_table.php' => database_path('migrations/0001_01_01_000003_add_legal_consent_cache_to_users_table.php'),
        ], 'legal-consent-users-cache');

        $this->publishes([
            __DIR__.'/../database/migrations/optional/0001_01_01_000004_backfill_v1_legal_acceptances.php' => database_path('migrations/0001_01_01_000004_backfill_v1_legal_acceptances.php'),
        ], 'legal-consent-backfill');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/legal-consent'),
        ], 'legal-consent-views');

        // WireKit-flavored variants — publishing this tag overrides the plain stubs with the
        // WireKit-themed versions (opt-in; requires WireKit in the host app).
        $this->publishes([
            __DIR__.'/../resources/views/wirekit/consent-checkboxes.blade.php' => resource_path('views/vendor/legal-consent/consent-checkboxes.blade.php'),
            __DIR__.'/../resources/views/wirekit/consent-banner.blade.php' => resource_path('views/vendor/legal-consent/consent-banner.blade.php'),
            __DIR__.'/../resources/views/wirekit/consent-settings.blade.php' => resource_path('views/vendor/legal-consent/consent-settings.blade.php'),
        ], 'legal-consent-wirekit');

        $this->publishes([
            __DIR__.'/../lang' => lang_path('vendor/legal-consent'),
        ], 'legal-consent-lang');
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
