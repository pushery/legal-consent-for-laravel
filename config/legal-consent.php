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
    |   - informational                  a page you must PUBLISH but nobody agrees to:
    |                                    Impressum (§ 5 DDG), cookie policy, accessibility
    |                                    statement. It uses the same editor, review gate and
    |                                    frozen publishing as the rest, and never appears at
    |                                    registration, never gates access, never sends a
    |                                    notice, and falls back to your default_locale when
    |                                    a translation is missing.
    |
    | Whether a document needs an explicit opt-in FOLLOWS from its legal basis — it is
    | derived at publish time, never configured: only a real consent may be opt-in, and it
    | always must be. Set the basis correctly and the rest follows.
    |
    | EVERY registered document except an `informational` one appears on the registration
    | form. That is the default because a document that asks something has to be asked
    | before an account exists. Two ways out, and they are not interchangeable:
    |
    |   - The page binds NOBODY — an Impressum, a cookie policy, an accessibility
    |     statement. Use `legal_basis => 'informational'`. It leaves the form, the gate and
    |     the notice sweeps together, which is right: a record saying someone "accepted the
    |     Impressum" asserts a consent that does not exist in law.
    |   - The document DOES bind, but not at sign-up — you acknowledge it later, in-app.
    |     Add `'ask_at_registration' => false`. It leaves the rules, the checklist and the
    |     recorder together, and nothing else changes: a mandatory document still gates, so
    |     the subject meets it at the re-consent screen instead. This moves WHEN it is
    |     asked, never WHETHER.
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
        // A published page that binds nobody. Registering it costs you nothing at
        // sign-up — that is the whole point of the `informational` basis. The key is
        // `imprint` because the rest of this catalog is English and `lang/*/titles.php`
        // already headed it that way; every key here is an EXAMPLE you rename freely.
        'imprint' => [
            'source' => 'markdown',
            'legal_basis' => 'informational',
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

            // null = the app's `resources/legal`, resolved from the container when the driver is
            // built. Writing `resource_path('legal')` here instead would put an ABSOLUTE path into
            // a file that gets cached: `config:cache` stores the evaluated value, so a cache built
            // in one directory (a deploy release folder, a container image, a colleague's machine)
            // would keep pointing at that one afterwards.
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

        /*
        | A brake on the size of ONE version's send. When set, the dispatch sweep skips any version
        | whose audience exceeds it — without stamping the watermark, so nothing is lost — and tells
        | you to look before you send:
        |
        |     php artisan legal-consent:dispatch-notices --dry-run
        |     php artisan legal-consent:dispatch-notices --force
        |
        | NULL by default, and that default is deliberate. A limit that ships on would withhold a
        | legally required notice from every installation that never asked for one — which is the
        | exact failure this package spent a release removing, and the expensive direction: under
        | P2B Art. 3(3) a change implemented without notice is VOID, while an oversized send is
        | merely expensive. Set it while you find your footing, then decide.
        */
        'max_recipients_per_run' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Change descriptions
    |--------------------------------------------------------------------------
    |
    | What a version CHANGED, in your own words, per locale — the content every real change notice
    | leads with. Author it before publishing and the notice carries it; author nothing and the
    | notice is exactly what it was before.
    |
    |     use Pushery\LegalConsent\Facades\ChangeItems;
    |
    |     ChangeItems::for('terms', 'de')
    |         ->headline('Wir haben zwei Auftragsverarbeiter aufgenommen.')
    |         ->impact('Deine Kontodaten werden künftig auch in Irland verarbeitet.')
    |         ->added('Cloudflare, Inc.', partyName: 'Cloudflare, Inc.', partyLocation: 'Irland (EU)', purpose: 'Auslieferung und DDoS-Schutz')
    |         ->restricted('§ 7 Haftung', 'Haftung für einfache Fahrlässigkeit ausgeschlossen.')
    |         ->save();
    |
    | The publish freezes that draft onto the version, in the same transaction, and it can never be
    | edited afterwards — it is the record of what subjects were told.
    |
    | `required` turns the description into a release precondition: a change that owes a notice
    | cannot be released until every locale has one. OFF by default, because a package that started
    | refusing existing releases the day it shipped a new field would be forcing a feature rather
    | than offering one. Turn it on once your process is ready for it.
    |
    */
    'change_items' => [
        'required' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | UI variant
    |--------------------------------------------------------------------------
    |
    | Which of the two bundled view sets the package renders. Every screen ships twice: a
    | framework-agnostic one built from bare `<h2>`, `<ul>` and `<li>`, and a WireKit-native one
    | built from real `<x-wirekit::*>` components.
    |
    | `auto` (the default) serves the WireKit set when `pushery/wirekit` is installed AND at least
    | the version this package's views are tested against; otherwise the plain set. Before this
    | key existed the WireKit set was reachable ONLY by publishing `--tag=legal-consent-wirekit`,
    | and nothing said so: an unstyled view RENDERS, so no test goes red, no exception is raised
    | and nothing is logged. A WireKit application therefore served raw HTML on its consent
    | screens — including the re-consent gate, the one screen a subject cannot get past — and the
    | only way to notice was to look.
    |
    | The version floor is the reason `auto` checks more than presence. A Blade component tag
    | compiles unconditionally, so switching a consumer onto views that name a component their
    | installed WireKit does not have would turn a silent styling bug into a hard exception —
    | and on the gate, at the moment a legal change lands. The package only ever auto-serves
    | views it has proven against; below the floor it stays plain and `legal-consent:doctor`
    | says why.
    |
    | `plain` and `wirekit` pin the choice regardless of what is installed. Pin `wirekit` to run
    | the themed set against a WireKit below the floor — deliberately, having read the above.
    |
    | A view you publish into `resources/views/vendor/legal-consent` still wins over both: your
    | copy is checked first, exactly as before.
    |
    */
    'ui' => [
        'variant' => 'auto',
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | `consent_name` is the named route the enforcement middleware redirects to
    | (your app defines it). The headless JSON API is opt-in via `api`.
    |
    | `return_to_intended` (opt-in): when a re-consent GATE is fully cleared, send the
    | subject back to the URL the middleware intercepted (stashed via redirect()->guest),
    | falling back to `home`. Off by default so an existing embed keeps its in-place
    | "all current" confirmation; a settings-page embed never redirects regardless.
    |
    | `web` registers ONE session-backed route, `POST {web_prefix}/consent/withdraw`, which is
    | what the framework-agnostic settings stub's withdraw button posts to. Turn it on and the
    | presenter starts filling each withdrawable entry's `withdraw_url`; leave it off and the
    | stub renders no withdraw form at all rather than one that goes nowhere.
    |
    | It is separate from `api` because the two answer different callers. The API sits behind the
    | `api` group — no session, no CSRF — and replies 204, which is right for a client and useless
    | for a form: the subject would land on a blank page. This one sits behind `web` + `auth` and
    | redirects back with a flashed confirmation.
    |
    | Withdrawal only. Objecting and terminating stay on the API and the Livewire component; no
    | bundled plain view offers them, and a public session-backed surface is not widened for a
    | case nothing asks for.
    |
    | `api_throttle` is a rate limit this package applies itself, and it is separate from
    | `api_middleware` on purpose. Since Laravel 11 the `api` group carries a limiter only if your
    | application called `throttleApi()` — the name promises nothing — and behind these routes sits
    | an append-only ledger: four write endpoints, no de-duplication, and no pruning by default, so
    | every accepted request is a row that stays. Keeping it out of `api_middleware` means replacing
    | that list (which you are meant to do, to supply your own auth) does not drop the limit with
    | it. The value is passed straight to Laravel's `throttle` middleware, so anything it accepts
    | works: `'60,1'` for sixty a minute, or the name of a limiter you registered. Set it to null
    | to take the limit off entirely.
    |
    */
    'routes' => [
        'consent_name' => 'legal.consent',
        'consent_path' => '/legal-consent',
        'return_to_intended' => false,
        'home' => '/',
        'api' => false,
        'api_prefix' => 'legal',
        'api_middleware' => ['api', 'auth'],
        'api_throttle' => '60,1',
        'web' => false,
        'web_prefix' => 'legal',
        'web_middleware' => ['web', 'auth'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Change-notice mail
    |--------------------------------------------------------------------------
    |
    | The seams on the three change notices. TOP-LEVEL and not inside `notifications`,
    | because `mergeConfigFrom()` merges one level deep: a key added inside a block your
    | published file already declares is ABSENT at runtime, not merely undocumented.
    |
    | Every seam is inert by default except `view`. That matters more here than elsewhere:
    | the notice body is hashed into an append-only proof row, so a seam that changed the
    | mail without being asked for would move the bytes of a document nobody can correct
    | afterwards.
    |
    | `view` — the package's own Markdown shell, and the one default that is NOT inert.
    | Before it, a German § 126b declaration went out inside Laravel's global template with
    | "Hello!", "Regards," and "If you're having trouble clicking", resolved from YOUR
    | application's translations. Set it to null to go back to that template. It must stay a
    | MARKDOWN view: `->view()` empties the mail's lines, and the proof body would collapse
    | to the subject while still reporting its mandatory content as present.
    |
    | `identity` — § 126b BGB wants "eine lesbare Erklärung, in der die Person des
    | Erklärenden genannt ist". Name yours and it is appended to the notice AND therefore to
    | the proof row. Left null, nothing is added and your notices are byte-for-byte what they
    | were. In a MULTI-TENANT application do not use this block: bind your own
    | `ResolvesNoticeIdentity` instead — one global declarant names the wrong legal person in
    | every tenant but one, which is worse than naming none.
    |
    | `notification` — swap a mode's notification class for your own subclass. A value that
    | is not a notification able to render a mail is ignored in favor of the shipped class,
    | rather than killing a queued sweep halfway through with subjects already notified.
    |
    */
    'notice_mail' => [
        'view' => 'legal-consent::mail.notice',
        // A plain name resolves against Laravel's own themes; a `::`-namespaced one is a view.
        // The package ships `legal-consent::mail.theme` — publish `legal-consent-mail` and
        // point this at it to take the styling over.
        'theme' => null,
        'from' => ['address' => null, 'name' => null],
        'reply_to' => null,
        'subject_prefix' => null,
        'subject_effective_date' => false,

        'identity' => [
            'declarant' => null,
            'postal_address' => null,
            'imprint_url' => null,
            'privacy_url' => null,
            'reply_to' => null,
        ],

        'notification' => [
            'active_reconsent' => null,
            'deemed_consent' => null,
            'info_push' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Where a document is readable
    |--------------------------------------------------------------------------
    |
    | This package stores and freezes legal texts; it does not own the pages that
    | display them. Set a resolver and every surface that shows a document — the
    | registration checkboxes, the re-consent gate, and "Your consents" — links its
    | title to the full text. Configure an INVOKABLE CLASS-STRING:
    |
    |   'document_url' => App\\Legal\\DocumentUrl::class,
    |
    | with `__invoke(LegalDocument $document): ?string` returning the URL. It is
    | resolved from the container, so it may take constructor dependencies.
    |
    | Returning null (or leaving this null) renders the title as plain text, which is
    | what every release before 0.13 did. Nothing breaks; the link is simply absent.
    |
    | It is worth setting. A subject asked to agree, or to withdraw, has to be able to
    | read what they are deciding about: Art. 7(1) and (2) GDPR want consent to be
    | informed and the request intelligible, § 305 Abs. 2 BGB wants the terms
    | retrievable before agreeing, and the re-consent gate is the sharpest case,
    | because there the subject cannot continue until they agree.
    |
    | A closure — `fn ($document) => route('legal', [$document->key, $document->locale])`
    | — also works and is fine while iterating locally. Do NOT ship one: it makes
    | `php artisan config:cache` fail in the deploy, and nothing before that point
    | reproduces it. `legal-consent:doctor` reports a closure here for that reason.
    | A misconfigured value yields no link rather than a broken one.
    |
    | This is a TOP-LEVEL key on purpose. `mergeConfigFrom()` merges one level deep, so
    | a key added inside an already-published block is absent at runtime for every
    | installation that published this file — not merely undocumented.
    |
    */
    'document_url' => null,

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
    | Gate subject predicate
    |--------------------------------------------------------------------------
    |
    | Which authenticated subjects the enforcement middleware gates. Null gates every
    | authenticated `Model` (the default). Set a predicate — return false to let a subject
    | through — so the gate can be ordered AFTER your own verification / onboarding /
    | suspension gates instead of overtaking them (e.g. do not ask an unverified user for
    | legally-binding consent before they confirm their address). Configure an INVOKABLE
    | CLASS-STRING:
    |
    |   'subject_filter' => App\\Legal\\SubjectFilter::class,
    |
    | with `__invoke(Model $subject): bool`. It is resolved from the container.
    |
    | A closure — `fn ($subject) => ! $subject instanceof MustVerifyEmail || $subject->hasVerifiedEmail()`
    | — also works and is fine while iterating locally. Do NOT ship one: it makes
    | `php artisan config:cache` fail in the deploy, and nothing before that point
    | reproduces it. `legal-consent:doctor` reports a closure here for that reason.
    | A misconfigured predicate fails SAFE — the subject stays gated.
    */
    'gate' => [
        'subject_filter' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Double opt-in
    |--------------------------------------------------------------------------
    |
    | The two halves of a double opt-in are two ledger rows: `Consent::requestConfirmation()`
    | writes an `optin_requested` row, `Consent::confirm()` writes a `confirmed` one. Only the
    | second makes the consent held — the first is a declaration nobody has yet tied to the address
    | it names, which for advertising e-mail is precisely NOT a valid consent (§ 7 Abs. 2 UWG with
    | Art. 7 DSGVO, and the burden of proof is the controller's under Art. 7(1)).
    |
    | `confirm_within` is how long a request stays confirmable — a relative-time string Carbon can
    | parse ('7 days', '48 hours'), or null for no limit. Null is the default because a limit
    | nobody chose would start refusing confirmations an application was already accepting.
    |
    | Set it and you get the ledger-side half of what a signed URL's expiry does inside the link.
    | An application that signs its confirmation links has that check twice, which is harmless; one
    | that does not has it exactly once, which is the case this key is for.
    |
    | A confirmation is refused for two further reasons regardless of this setting: there is no
    | pending request (already confirmed, withdrawn, or never made), or a new MAJOR version was
    | published in between — confirming that one would freeze a text the subject never read.
    |
    */
    'double_opt_in' => [
        'confirm_within' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Registration integration
    |--------------------------------------------------------------------------
    |
    | Warning: this default is safe only because a registration form validated the tick.
    |
    | For a CONSENT document the recorder checks the submitted field itself and
    | skips the key when it is absent. For a CONTRACT or an ACKNOWLEDGEMENT it
    | does not: those are mandatory, RegistrationRules makes them required, and
    | re-checking here would be a second truth about the same thing. So the
    | recorder accepts them unconditionally and relies on the form.
    |
    | Sign people in through an external provider — OAuth, SSO, an invitation
    | link — and there is no form. The callback carries no such fields, so
    | nothing validated the tick. With this switch on, the first callback writes
    | an acceptance row for every mandatory document without a human having done
    | anything — in the one table whose entire purpose is to prove that a human
    | did.
    |
    | The recorder now SAYS so: recording a mandatory document whose
    | `legal_<key>` field is absent from the request logs a warning naming the
    | keys. It still records them — refusing would break every application whose
    | form names the fields differently — so treat that warning as the signal
    | that this switch is wrong for your sign-in route, not as a failed write.
    |
    | If your application has no registration form, turn this OFF and capture
    | the first acceptance where it actually happens: an interstitial after
    | authentication and before first use, recorded under
    | ConsentMethod::FirstUseGate.
    |
    | `without_form_fields` decides what that warning IS. `warn` (the default) logs and records;
    | `refuse` raises UnevidencedConsentException and records NOTHING — the recorder resolves every
    | document before its first write, so a registration keeps all of its consents or none.
    |
    | The default is `warn` and not `refuse`, and that is a deliberate asymmetry rather than
    | timidity. The check can only look for the field name `RegistrationRules` generates. An
    | application with its OWN registration form, naming its fields differently, validates the tick
    | perfectly well and still carries no `legal_terms` on the request — under `refuse` it would get
    | a failed registration the day it updated, on the one path every current consumer uses. A
    | warning to such an application is noise; a refusal is an outage.
    |
    | Turn it on where the flag was written for: a sign-in through an external provider, where there
    | is genuinely no form and no tick, so the alternative to refusing is a proof row asserting
    | something that did not happen. An unrecognized value means `warn` — a typo must never be the
    | thing that starts failing registrations — and `legal-consent:doctor` names it.
    */
    'registration' => [
        'listen_to_registered_event' => true,
        'without_form_fields' => 'warn',
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
    | is legally wrong for several regimes. At publish, a SCHEDULED change that owes a notice
    | must give at least the minimum lead for its mode AND its regime; the two are combined,
    | not chosen between, because a deemed-consent change under P2B owes both the § 308 Nr. 5
    | benchmark and the Art. 3 standstill. Override per document via `min_lead_days` in
    | `documents` — which may shorten a period only where the law fixes no floor.
    |
    | Which key a change reads:
    |
    |   mode   active re-consent  -> active_reconsent_min_days
    |          deemed consent     -> deemed_consent_min_days
    |          info-only          -> nothing; its regime is the only source
    |          silent editorial   -> nothing; it owes no notice, and declaring a regime on
    |                                one is refused rather than silently ignored
    |
    |   regime psd2_675g -> psd2_min_days      (FLOOR 60 — § 675g Abs. 1 / Art. 54 PSD2)
    |          p2b       -> p2b_standstill_days (FLOOR 15 — Reg. 2019/1150 Art. 3(2))
    |          eecc      -> eecc_min_days       (FLOOR 30 — Dir. 2018/1972 Art. 105(4))
    |          gdpr      -> privacy_advance_days (no floor: WP260 says "well in advance",
    |                                             which is guidance, not a number)
    |          bgb_agb   -> nothing; § 308 Nr. 5's "angemessene Frist" IS the mode benchmark
    |          dcd_327r  -> nothing; § 327r Abs. 2 fixes no number (see below)
    |
    | Every key below is a lead time and every one of them is read. That is the whole rule for
    | this block, and it is why the § 327r Abs. 3 free-termination window is NOT among them: that
    | window runs from the LATER of notice and modification, so it is a figure your notice has to
    | state, not a period before anything. It is also fixed at 30 days by statute, so a setting for
    | it would offer to change a number nobody can change. The package neither reads it nor stores
    | it; state it in your own notice text.
    |
    */
    'notice_periods' => [
        'active_reconsent_min_days' => 60, // § 308 Nr. 5 / BGH XI ZR 26/20 grace (also MATERIAL_MIN_LEAD_DAYS)
        'deemed_consent_min_days' => 60,   // the 2-month § 308 / § 675g benchmark
        'psd2_min_days' => 60,             // HARD: § 675g(1) BGB / Art. 54 PSD2 (2 months)
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
    | Optional HMAC secret that KEYS the tamper-evidence chain. Held OUTSIDE the database
    | (env / secret store, never in `legal_consents`). When set, row hashes use HMAC-SHA-256
    | instead of a bare SHA-256, so an actor with only table-write access — who does not hold the
    | secret — can no longer re-chain a tampered row into a self-consistent chain the verifier
    | reports as intact (the re-chain gap called out in the tamper-evidence notes). Set it BEFORE
    | the first chained row: the rows are append-only and cannot be re-keyed, so changing the secret
    | invalidates prior links. Null keeps the legacy unkeyed hash. Rotation / KMS sourcing is the
    | app's concern — this reads whatever the env provides.
    */
    'tamper_evidence_key' => env('LEGAL_CONSENT_TAMPER_KEY'),

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
