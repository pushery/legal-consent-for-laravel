<?php

declare(strict_types=1);

use Pushery\LegalConsent\Content\Drivers\DraftDocumentSource;
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
    | Whether a document needs an explicit opt-in FOLLOWS from its legal basis — it is
    | derived at publish time, never configured: only a real consent may be opt-in, and it
    | always must be. Set the basis correctly and the rest follows.
    |
    */
    'documents' => [
        'terms' => [
            'source' => 'markdown',
            'legal_basis' => 'contract',
        ],
        'privacy' => [
            'source' => 'markdown',
            'legal_basis' => 'acknowledgement',
        ],
        'newsletter' => [
            'source' => 'drafts',
            'legal_basis' => 'consent',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Content sources
    |--------------------------------------------------------------------------
    |
    | Where legal texts come from.
    |
    | `markdown` is the recommended default (git-diffable, PR-reviewable, zero database).
    |
    | `drafts` is the admin-maintained store: texts are edited in your own screens, a human
    | reviews the exact bytes, and only then can a publish freeze them. The gate lives in the
    | source itself, so `php artisan legal-consent:publish` cannot bypass it either.
    |
    | Any other source is your own class implementing
    | Pushery\LegalConsent\Content\LegalDocumentSource — name it as the `driver` and it is
    | resolved from the container.
    |
    */
    'sources' => [
        'markdown' => [
            'driver' => MarkdownFilesDriver::class,

            // null = the app's `resources/legal`. It is resolved at runtime rather than written
            // here as `resource_path('legal')`, because this file is require'd on every app boot
            // (mergeConfigFrom runs in register()) and that helper only exists in
            // laravel/framework — which this package deliberately does not require.
            'path' => null,
        ],
        'drafts' => [
            'driver' => DraftDocumentSource::class,
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

        // How long the gate may keep its cached "which versions are enforceable" set. Only the
        // SET is cached, never a subject's satisfaction — so this bounds how late a scheduled
        // enforce_from boundary starts gating, nothing about what gets recorded. A publish
        // flushes it immediately; an out-of-band is_active write needs legal-consent:cache-flush.
        'enforceable_ttl' => 60,
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
        'close_objection_windows' => true,

        // OFF by default, unlike its two siblings: this sweep DELETES. Turning it on is how the
        // `retention_after_end` period above stops being a statement and starts being enforced —
        // until then nothing ever removes an expired record. Opt in deliberately, once.
        'prune' => false,
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
    | Advance-notice periods (per regime)
    |--------------------------------------------------------------------------
    |
    | Advance-notice periods are per-regime, never one global value — a single number
    | is legally wrong for several regimes. At publish, a SCHEDULED active-re-consent or
    | deemed-consent change must give at least the minimum lead for its regime (payment
    | contracts have the hard 2-month rule); the info-only periods are documented defaults
    | for the notice content. Override per document via a `min_lead_days` key in `documents`.
    |
    */
    'notice_periods' => [
        'active_reconsent_min_days' => 60, // § 308 Nr. 5 / BGH XI ZR 26/20 grace (also MATERIAL_MIN_LEAD_DAYS)
        'deemed_consent_min_days' => 60,   // the 2-month § 308 / § 675g benchmark
        'psd2_min_days' => 60,             // HARD: § 675g(1) BGB / Art. 54 PSD2 (2 months)
        'dcd_termination_days' => 30,      // § 327r Abs. 3 free-termination window
        'p2b_standstill_days' => 15,       // Reg. (EU) 2019/1150 Art. 3 minimum standstill
        'eecc_min_days' => 30,             // Dir. (EU) 2018/1972 Art. 105(4)
        'privacy_advance_days' => 30,      // "well in advance" (WP260) — a sane default, not statutory
    ],

    /*
    |--------------------------------------------------------------------------
    | Durable medium
    |--------------------------------------------------------------------------
    |
    | A disadvantageous or materially-adverse change must be delivered on a durable
    | medium (dauerhafter Datenträger / Textform § 126b BGB; CJEU C-375/15 BAWAG). When
    | `proof` is on, the notice dispatch writes an append-only legal_notices proof row per
    | subject. `channels` are the durable-medium delivery channels.
    |
    */
    'durable_medium' => [
        'proof' => true,
        'channels' => ['mail'],
    ],

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
    | all tenants. The tenant column on both tables is the fixed constant `tenant_id`
    | (Pushery\LegalConsent\Support\TenantContext::COLUMN) — there is no config knob for it.
    */
    'tenancy' => [
        'enabled' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin screens
    |--------------------------------------------------------------------------
    |
    | The publishable LegalTextManager / LegalTextEditor Livewire components fail CLOSED: with
    | no ability named here they 404, so the package never exposes an ungated publish button.
    | Opt in by naming a Gate ability the current user must pass; there is no opt-out.
    |
    */
    'admin' => [
        'ability' => null,
    ],

];
