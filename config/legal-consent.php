<?php

declare(strict_types=1);

use Pushery\LegalConsent\Content\Drivers\CmsAdapterDriver;
use Pushery\LegalConsent\Content\Drivers\DatabaseDriver;
use Pushery\LegalConsent\Content\Drivers\MarkdownFilesDriver;

return [

    /*
    |--------------------------------------------------------------------------
    | Locales
    |--------------------------------------------------------------------------
    */
    'default_locale' => 'de',
    'locales' => ['de', 'en'],
    'fallback_locale' => 'de',

    /*
    |--------------------------------------------------------------------------
    | Document registry
    |--------------------------------------------------------------------------
    |
    | The documents this app manages. Each has a source (see `sources` below) and
    | a legal_basis — the load-bearing distinction:
    |   - contract        Art. 6(1)(b): mandatory, blocking, NOT withdrawable.
    |   - acknowledgement  Art. 13:      mandatory, "zur Kenntnis genommen", never
    |                                    "ich willige ein".
    |   - consent          Art. 6(1)(a): a real consent — voluntary, NEVER required
    |                                    (Kopplungsverbot Art. 7(4)), withdrawable.
    |
    */
    'documents' => [
        'terms' => [
            'source' => 'markdown',
            'legal_basis' => 'contract',
            'requires_explicit_optin' => false,
        ],
        'privacy' => [
            'source' => 'markdown',
            'legal_basis' => 'acknowledgement',
            'requires_explicit_optin' => false,
        ],
        'newsletter' => [
            'source' => 'database',
            'legal_basis' => 'consent',
            'requires_explicit_optin' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Content sources
    |--------------------------------------------------------------------------
    |
    | Where legal texts come from. `markdown` is the recommended default (git-
    | diffable, PR-reviewable). `cms` adapts a foreign CMS via a resolver class or
    | inline closure that returns a Pushery\LegalConsent\Content\RawDocument.
    |
    */
    'sources' => [
        'markdown' => [
            'driver' => MarkdownFilesDriver::class,
            'path' => resource_path('legal'),
        ],
        'database' => [
            'driver' => DatabaseDriver::class,
        ],
        'cms' => [
            'driver' => CmsAdapterDriver::class,
            // 'resolver' => \App\Legal\PageResolver::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Markdown rendering
    |--------------------------------------------------------------------------
    */
    'markdown' => [
        'html_input' => 'strip',
        'allow_unsafe_links' => false,
        'max_nesting_level' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rendered-document cache
    |--------------------------------------------------------------------------
    |
    | The manager caches rendered documents keyed by the source fingerprint, so a
    | content change auto-invalidates. Use a shared store (redis) in production.
    |
    */
    'cache' => [
        'store' => env('LEGAL_CONSENT_CACHE_STORE'),
        'ttl' => 86400,
        'prefix' => 'legal:doc',
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    */
    'notifications' => [
        'channels' => ['mail', 'database'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | `consent_name` is the named route the enforcement middleware redirects to
    | (your app defines it). The headless JSON API is opt-in via `api`.
    |
    */
    'routes' => [
        'consent_name' => 'legal.consent',
        'consent_path' => '/legal-consent',
        'api' => false,
        'api_prefix' => 'legal',
        'api_middleware' => ['api', 'auth'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Enforcement middleware
    |--------------------------------------------------------------------------
    */
    'middleware' => [
        'allowlist_routes' => [],
        'allowlist_paths' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Registration integration
    |--------------------------------------------------------------------------
    */
    'registration' => [
        'listen_to_registered_event' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduling
    |--------------------------------------------------------------------------
    */
    'schedule' => [
        'dispatch_notices' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | How long to keep proof after a consent's relevance ends. Default 3 years
    | (§ 31 Abs. 2 OWiG / § 195 BGB). A relative-time string parsed by Carbon.
    |
    */
    'retention_after_end' => '3 years',

    /*
    |--------------------------------------------------------------------------
    | Optional features
    |--------------------------------------------------------------------------
    */

    /*
    | Tamper-evidence hash chain. When true, every new ledger row is linked to the
    | subject's previous row via `prev_record_hash`, so a later edit, deletion,
    | insertion, or reorder within a subject's history is detectable. Audit the chain
    | with `php artisan legal-consent:verify-ledger` (non-zero exit on a break). The
    | chain is per-subject; a legitimate retention prune shows as an expected
    | discontinuity. Combine with the append-only DB trigger (Postgres/MySQL) for the
    | strongest guarantee. Off by default (adds one read per write when on).
    */
    'tamper_evidence' => false,

    /*
    | Age gate (Art. 8 DSGVO). When enabled, the registration ruleset additionally
    | requires an `age_confirmed` checkbox to be accepted — the minimum-age attestation.
    | `threshold` only shapes the message (the German floor is 16; some member states set
    | 13–16). The package gates on the attestation; verifying the ACTUAL age (and handling
    | parental consent below the threshold) remains the consuming app's responsibility.
    */
    'age_gate' => [
        'enabled' => false,
        'threshold' => 16,
    ],

    /*
    | Multi-tenancy. When enabled, legal documents and consents are scoped to a tenant.
    | Register a resolver in a service provider's boot() that returns the current tenant id:
    |
    |     app(\Pushery\LegalConsent\Support\TenantContext::class)
    |         ->resolveUsing(fn () => auth()->user()?->tenant_id);
    |
    | Documents are published, gated, and recorded per tenant; each tenant gets its own
    | active version of a (key, locale). Admin sweeps (prune, dispatch-notices) run across
    | all tenants. `column` is the tenant column on both tables ('' = the shared bucket).
    */
    'tenancy' => [
        'enabled' => false,
        'column' => 'tenant_id',
    ],

];
