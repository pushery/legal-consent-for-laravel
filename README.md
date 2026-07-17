<p align="center">
  <a href="https://github.com/pushery/legal-consent-for-laravel">
    <img src="art/header.png" alt="Legal Consent for Laravel" width="100%">
  </a>
</p>

# Legal Consent for Laravel

[![Latest Version](https://img.shields.io/packagist/v/pushery/legal-consent-for-laravel.svg)](https://packagist.org/packages/pushery/legal-consent-for-laravel)
[![PHP Version](https://img.shields.io/packagist/dependency-v/pushery/legal-consent-for-laravel/php.svg)](https://packagist.org/packages/pushery/legal-consent-for-laravel)
[![PHPStan](https://img.shields.io/badge/PHPStan-max-blue.svg)](https://phpstan.org)
[![Code Style](https://img.shields.io/badge/code%20style-pint-orange.svg)](https://laravel.com/docs/pint)
[![License](https://img.shields.io/packagist/l/pushery/legal-consent-for-laravel.svg)](LICENSE)

Court-proof, versioned legal consent for Laravel. It is a **document-acceptance
ledger**: the package **renders and proves** your legal texts — it does not own them.

## Why this exists

A registration does **three legally distinct things**, and treating them as one
(a single "I accept everything" checkbox) is a common — and real — GDPR violation:

| Document | Legal basis | UI | Blocking? | Withdrawable? |
|---|---|---|---|---|
| **Terms / contract** | Art. 6(1)(b) — contract | "I accept …" checkbox | yes | no (you cancel, not withdraw) |
| **Privacy notice** | Art. 13/14 — information | "I have read …" — **never** "I consent" | takes notice | n/a |
| **Marketing / analytics** | Art. 6(1)(a) — consent | separate, granular opt-in | **no** (Art. 7(4)) | yes, any time (Art. 7(3)) |

This package keeps them separate by design, and proves acceptance the way the law
requires (Art. 7(1); EDPB 05/2020 §108): it stores the **exact text a user was shown**,
its version and hash, and the server-side context — not just a timestamp.

Highlights:

- **Append-only audit ledger** — every acceptance/acknowledgement/withdrawal is one
  immutable row with denormalized proof (hardened by a DB trigger on Postgres/MySQL and
  an app-layer guard everywhere).
- **Versioned documents** — a SHA-256 hash detects a change; you classify how it must be
  communicated (its notice mode); only a core contract change forces active re-consent.
- **Four notice modes** — an editorial change is silent; an info-only change (a § 675g
  payment-contract update, a material privacy notice) is *announced but never blocks*; a minor
  contract change can bind by silence with an objection window (Zustimmungsfiktion, § 308
  Nr. 5 BGB); only a material core change gates — each with durable-medium delivery proof.
- **Interchangeable content sources** — Markdown files (default), or an admin-maintained draft
  store, edited and reviewed in your own screens before a publish freezes it.
- **Fortify-optional** — record consent three ways (a trait, an event listener, or a
  headless JSON API). `laravel/fortify` is never required.
- **Optional, off by default** — a tamper-evidence hash chain (`legal-consent:verify-ledger`),
  an Art. 8 age gate, and multi-tenancy scoping — each a single config switch.
- **Optional reactive UI** — plain Blade stubs by default; opt-in Livewire components and a
  WireKit-flavored variant when you want them (no hard Livewire/Flux dependency).

## Compatibility

| | Supported |
|---|---|
| **PHP** | 8.4, 8.5 |
| **Laravel** | 13.x |
| **Databases** | SQLite · PostgreSQL · MySQL 8.4 LTS |

Every database-touching path is tested against real PostgreSQL **and** real MySQL 8.4 (not
just SQLite), so it runs on Laravel Cloud (serverless Postgres + MySQL 8.4 LTS) out of the
box. PostgreSQL is primary, but the append-only and one-active-version guarantees are
enforced portably in the app layer on every engine.

## Installation

```bash
composer require pushery/legal-consent-for-laravel
```

The service provider is registered automatically. Publish the config and run the
migrations:

```bash
php artisan vendor:publish --tag=legal-consent-config
php artisan migrate
```

## Quick start

1. **Write your texts** as Markdown with frontmatter at `resources/legal/{type}/{locale}.md`:

   ```markdown
   ---
   version: "1.0.0"
   title: Nutzungsbedingungen
   ui_wording: Ich akzeptiere die Nutzungsbedingungen.
   ---
   # Nutzungsbedingungen

   …
   ```

   `version` drives the ledger and decides what counts as a major change. Two optional keys,
   `announce_at` and `enforce_at` (or `effective_at`), let the text itself carry its schedule —
   the publish flags below override them. How a change is *classified* is never taken from the
   file: that is a decision you make per publish, at the command line.

2. **Publish a version** (freezes it into the ledger; you must classify the change):

   ```bash
   php artisan legal-consent:publish terms de --material
   ```

   An initial version takes effect at once — there is nothing to give notice of yet. A
   *later* material change is a different act: it must be announced ahead of its effective
   date, with the advance period its regime requires (see
   [Change classes & notice modes](#change-classes--notice-modes)).

3. **Give any model a consent ledger** with the trait:

   ```php
   use Illuminate\Foundation\Auth\User as Authenticatable;
   use Pushery\LegalConsent\Concerns\HasLegalConsents;

   class User extends Authenticatable
   {
       use HasLegalConsents;
   }
   ```

4. **Enforce re-consent** by adding the middleware to your authenticated routes:

   ```php
   // bootstrap/app.php
   ->withMiddleware(function (Middleware $middleware): void {
       $middleware->appendToGroup('web', \Pushery\LegalConsent\Http\Middleware\EnsureLegalConsent::class);
   })
   ```

   It redirects to your `legal.consent` route (or returns `409 legal_consent_required`
   for JSON) when a mandatory document has an outstanding new major version.

## Recording consent (three ways)

All three write the same ledger through one recorder and share an idempotency flag, so
they never double-write.

**Way A — Fortify `CreateNewUser` trait** (strongest proof context):

```php
use Pushery\LegalConsent\Concerns\RecordsRegistrationConsent;

class CreateNewUser implements CreatesNewUsers
{
    use RecordsRegistrationConsent;

    public function create(array $input): User
    {
        Validator::make($input, [/* … */] + $this->consentRules(), $this->consentMessages())->validate();

        $user = User::create([/* … */]);

        $this->recordRegistrationConsent($user, $input);

        return $user;
    }
}
```

**Way B — the `Registered` event listener** (no Fortify): it is registered
automatically; toggle it with `legal-consent.registration.listen_to_registered_event`.

**Way C — the headless JSON API** (opt-in via `legal-consent.routes.api`):

```
POST /legal/consent    { "document_key": "newsletter" }  → 201
POST /legal/withdraw   { "document_key": "newsletter" }  → 204 (422 if not withdrawable)
POST /legal/object     { "document_key": "terms" }       → 201 (a Widerspruch)
POST /legal/terminate  { "document_key": "terms" }       → 201 (a free termination)
GET  /legal/status                                        → 200 per-document status
```

Or use the facade / manager directly:

```php
use Pushery\LegalConsent\Facades\Consent;
use Pushery\LegalConsent\Support\ConsentContext;
use Pushery\LegalConsent\Enums\ConsentMethod;

Consent::accept($user, 'terms', ConsentContext::forMethod(ConsentMethod::SettingsToggle));
Consent::withdraw($user, 'newsletter', ConsentContext::forMethod(ConsentMethod::SettingsToggle));
$outstanding = Consent::outstanding($user);   // documents still owed
$history = Consent::history($user);           // Art. 15/20 export payload
```

### Objecting and terminating

Two rights need their own record, because a change can be answered without accepting it:

```php
// A Widerspruch: rebuts a deemed-consent change (§ 308 Nr. 5 lit. a BGB) — the subject is
// then never deem-accepted — or objects to legitimate-interest processing (Art. 21 GDPR).
Consent::object($user, 'terms', ConsentContext::forMethod(ConsentMethod::SettingsToggle));

// The free right to terminate before a change takes effect (§ 675g / § 327r Abs. 3 BGB, P2B).
Consent::terminate($user, 'terms', ConsentContext::forMethod(ConsentMethod::SettingsToggle));
```

Each appends one immutable ledger row and fires an event — `ConsentObjected` or
`ConsentTerminated` — because only **your** app can act on it: the package cannot know which
processing an Art. 21 objection must stop, or how your contract ends. Listen and act:

```php
Event::listen(ConsentObjected::class, function (ConsentObjected $event): void {
    // Stop the objected-to processing for $event->consent->subject, where no other basis applies.
});
```

### The delivery proof

Every pushed change notice writes an append-only `legal_notices` row (unless you turn
`durable_medium.proof` off): who was informed, when, on which medium, the exact text sent, its
hash, and whether the notice carried its mandatory content. That is the evidence a durable-medium
notice actually went out (CJEU C-375/15) — audit it like any model:

```php
use Pushery\LegalConsent\Models\LegalNotice;

$proof = LegalNotice::query()->where('document_key', 'terms')->latest('sent_at')->get();
$deficient = LegalNotice::query()->where('mandatory_content_ok', false)->exists(); // should be false
```

The proof is append-only and holds personal data, so `legal-consent:prune` covers it on the
same retention rule as the consent ledger: superseded and orphaned rows past the period go,
while the **newest** notice per subject and document is kept — it is what proves the change
behind their current standing was lawfully announced.

## Change classes & notice modes

A change to a legal text is not one thing. The package models the **four** ways a change is
communicated and enforced — `NoticeMode` — so a change gets exactly the notice the law
requires and never more:

| Notice mode | When to use it | The subject must… | On silence | Gates access? |
|---|---|---|---|---|
| **`SilentEditorial`** (`--editorial`) | a typo, a clarification, a purely favourable change | nothing | bound | no |
| **`InfoPush`** (`--info`) | a material change that is **information only** — a payment-contract change under § 675g, a material privacy-notice update, a reference-rate change | nothing (may object or terminate) | takes effect regardless | no |
| **`DeemedConsent`** (`--deemed`) | a **minor/peripheral contract** change where silence may bind (Zustimmungsfiktion) | object before the deadline, else nothing | **silence binds** (§ 308 Nr. 5 BGB) | no |
| **`ActiveReconsent`** (`--active`, or the legacy `--material`) | a **material/core** contract change, or a new/expanded real consent | actively accept before it applies | not bound; the old terms continue | **yes** |

`legal-consent:dispatch-notices` routes each mode to the matching notification —
`LegalChangeInformational` ("no action required"), `DeemedConsentNotice` (carrying the
§ 308 Nr. 5 lit. b "silence counts as consent" warning), or `ReconsentRequired` — and, with
`durable_medium.proof` on, writes an append-only row to `legal_notices` proving the notice was
delivered. Only `ActiveReconsent` ever blocks access.

### The invariants (never violated)

- A **privacy notice** is *acknowledged*, never consented, and is **never gated** — a material
  privacy update is `InfoPush`, announced but non-blocking (WP260 rev.01).
- A **real consent** is never refreshed by silence or deemed consent; a material scope change
  needs a fresh, active opt-in (EDPB 05/2020).
- **Deemed consent lives only in the contract type**, and only for minor changes
  (BGH XI ZR 26/20, EuGH C-287/19).
- Advance-notice periods are **per regime**, never one global value (see `notice_periods`).

### Worked example — an info-only contract change ("no action required")

A payment-contract change your users only need to be *told* about:

```bash
php artisan legal-consent:publish terms de --info --regime=psd2_675g \
    --announce-at=2026-07-01 --enforce-at=2026-09-01
```

Once the announcement date passes, `dispatch-notices` emails each affected subject a
`LegalChangeInformational` ("we've updated our contract; no action is required"), records a
durable-medium proof row, and the change takes effect on the effective date — **no one is
blocked**. Render the dismissible heads-up banner from `ConsentBanner`:

```blade
@php($banners = app(\Pushery\LegalConsent\Support\ConsentBanner::class)->forSubject($user, app()->getLocale()))
@include('legal-consent::consent-banner', [
    'pending' => $banners['reconsent'],
    'informational' => $banners['informational'],
    'deemed' => $banners['deemed'],
    'consentUrl' => route('legal.consent'),
])
```

### Worked example — deemed consent with an objection window

A minor contract change where silence may bind, provided the subject gets an adequate window
to object and the § 308 Nr. 5 lit. b warning.

The statutory period is measured to the **objection deadline**, not to the effective date — the
subject must have the full period to actually object — and it is never waived, not even for an
immediate change. So the deadline must be at least `deemed_consent_min_days` after the
announcement, and the change takes effect after it:

```bash
php artisan legal-consent:publish terms de --deemed --regime=bgb_agb --offers-termination \
    --announce-at=2026-07-01 --objection-at=2026-08-30 --enforce-at=2026-09-01
```

`dispatch-notices` sends a `DeemedConsentNotice` carrying the warning and the free-termination
right. A subject who does nothing is bound: once the objection deadline passes,
`legal-consent:close-objection-windows` (hourly, auto-scheduled) records a `DeemedAccepted`
row — silence, made provable. A subject who **objects** or terminates in time is recorded
instead, and the fiction never applies to them.

### Active re-consent (a material core change)

A material change to the core of the contract still requires active agreement, with the full
grace period and a hard gate at the deadline:

```bash
php artisan legal-consent:publish terms de --active \
    --announce-at=2026-07-01 --enforce-at=2026-09-01
```

Both dates are validated — you cannot smuggle a short lead by omitting `--announce-at`, and
the minimum period is per regime (§ 675g's two months is hard). Use a **future** `--enforce-at`:
the lead-time guard only runs while the effective date is still ahead, so an already-past date
publishes an immediate gate with no grace (the dates above are illustrative — pick your own).
From `enforce_from`, the middleware blocks the subject until they re-accept the new major version. A SHA-256 hash
**detects** any text change; a human **classifies** it (the notice mode) at publish time; the
gate compares `major_version` only.

## Content sources

Each document declares a `source` in `config/legal-consent.php`:

- **`markdown`** (default) — git-diffable, PR-reviewable `.md` files under `resources/legal`.
- **`drafts`** — the admin-maintained draft store. Texts are authored and reviewed per locale in
  your own screens (below), and only a reviewed draft set can be released. Opt a document in with
  `'source' => 'drafts'`.

Any other value is your own class implementing `Pushery\LegalConsent\Content\LegalDocumentSource`,
named as the source's `driver` and resolved from the container.

## Maintaining legal texts

Two Livewire screens ship as publishable stubs — a **manager** (releases every locale of a document
atomically) and a per-locale **editor**. They are **opt-in and fail-closed**: name a Gate ability in
`admin.ability` and define it. With the ability unset, or the Gate denied, both screens return `404`
and never reveal they exist — there is no ungated publish path.

```php
// config/legal-consent.php
'admin' => ['ability' => 'manage-legal-texts'],

// a service provider
Gate::define('manage-legal-texts', fn ($user) => $user->isLegalAdmin());
```

Publish the plain stubs with `--tag=legal-consent-views`, the WireKit variants with
`--tag=legal-consent-wirekit`. The editor sanitizes on store, so its preview is the exact bytes a
publish freezes. Binding `Pushery\LegalConsent\Contracts\LegalTextTranslator` enables the editor's
"Translate" action; a machine translation is always produced unreviewed and must be reviewed by a
human before it can be released.

### Published versions are frozen

A published `legal_documents` row is immutable proof — the exact sanitized text a subject was shown
and the hash the ledger snapshots, one text because they are one row. A database trigger
(PostgreSQL, MySQL, SQLite) and a model hook reject any edit to a proof column after publish; a
direct write raises `LegalDocumentFrozenException` (through the model) or a database error (raw
SQL). Correcting a text is a **new version**, never an in-place edit.

### Rendering the public legal page

Render a public page from the frozen row — never the source, which can drift between an author's
edit and the next publish:

```php
use Pushery\LegalConsent\Facades\Consent;

$document = Consent::published('terms', app()->getLocale());

// $document?->html is the exact stored bytes and $document?->contentHash the exact stored hash —
// the same text the ledger proves. Returns null before that locale is published (render an "in
// preparation" shell), and never falls back to another locale.
```

## Commands

| Command | What it does |
|---|---|
| `legal-consent:publish {key} {locale?} --editorial\|--info\|--deemed\|--active` | Freeze the current source into a new active version under a notice mode (`--material` is the legacy alias of `--active`). Flags below. |
| `legal-consent:check-drift {key?} {locale?}` | Non-zero exit when a source has drifted from its published version (CI/cron). Narrow it to one document/locale with the optional arguments. |
| `legal-consent:dispatch-notices` | Notify subjects of a due legal change, routing by notice mode (hourly, idempotent, auto-scheduled). |
| `legal-consent:close-objection-windows` | Bind silence to deemed acceptance once a deemed-consent objection window closes (hourly, idempotent, auto-scheduled). |
| `legal-consent:prune` | Delete consent and notice records past the retention period (default 3 years); the current standing of an active subject is always kept. **Not scheduled by default** — see Retention below. |
| `legal-consent:cache-flush {key?} {locale?}` | Flush cached, rendered documents. |
| `legal-consent:verify-ledger` | Verify the tamper-evidence hash chain (non-zero exit on a break); only when `tamper_evidence` is on. |
| `legal-consent:verify-documents` | Verify every published row against its stored hash, and flag notice-mode divergence across a version's locales or a wording-locale mismatch (non-zero exit on a hard failure). Read-only — a frozen row is never repaired. |

### `legal-consent:publish` flags

Exactly one mode flag per publish; the rest are optional metadata.

| Flag | What it means |
|---|---|
| `--editorial` | Silent activation — a typo/formatting fix that changes no obligation. No notice. |
| `--info` | Actively announced, no action required, takes effect regardless. |
| `--deemed` | Silence counts as acceptance. Contract/terms only; requires `--objection-at`. |
| `--active` (`--material`) | The subject must actively accept before it applies. |
| `--regime=` | The legal regime the change falls under: `bgb_agb`, `psd2_675g`, `dcd_327r`, `gdpr`, `p2b`, or `eecc`. Validated against that set — an unrecognised value is **refused**. Today only `psd2_675g` carries its own advance period (§ 675g's hard two months); the rest are recorded on the version but fall to the generic re-consent/deemed default. |
| `--announce-at=` | ISO date the subjects are notified. Defaults to now. |
| `--enforce-at=` | ISO date enforcement begins. Omit for an immediate publish. |
| `--objection-at=` | ISO date the objection window closes (`--deemed`). Must leave the full statutory period after the announcement. |
| `--offers-termination` | The notice offers a free right to terminate before the effective date (§ 675g / § 327r). |
| `--keeps-unmodified` | The subject may keep the unmodified version (the DCD / § 327r escape hatch). |
| `--change-class=` | A free-form classification tag from your legal review (e.g. `agb_minor_peripheral`), stored with the version for the audit trail. |

**A major version bump forces its mode.** The publisher refuses `--info`/`--deemed` on a
major bump of a contract — a material core change cannot ride on silence or mere information
(BGH XI ZR 26/20) — and refuses anything but `--info` on a major privacy bump, because a
privacy notice is acknowledged, never gated. Publish a change that needs `--info`/`--deemed`
as a **minor or patch** bump of the source version.

## UI

The core is **headless** — it renders and proves, and never forces a UI framework on you.
Three levels, pick one:

1. **Plain Blade stubs** (default, no dependency) — the consent checkboxes, the grace-period
   banner, and a settings page. Publish and restyle them freely:

   ```bash
   php artisan vendor:publish --tag=legal-consent-views
   ```

2. **Livewire components** (opt-in) — reactive, drop-in versions of the interactive screens:
   `<livewire:legal-consent.reconsent-form />` and `<livewire:legal-consent.consent-settings />`
   — one-click accept, withdraw (Art. 7(3)), object, and terminate. They register
   automatically **only if** you have `livewire/livewire` installed, so the package stays
   dependency-free otherwise.

3. **WireKit-native variant** — if your app uses [WireKit](https://wirekit.app), publish the
   WireKit versions to override the plain ones:

   ```bash
   php artisan vendor:publish --tag=legal-consent-wirekit
   ```

   Built from real `<x-wirekit::*>` components — callouts, checkboxes, badges, and a live countdown
   on every deadline — so they inherit your theme instead of shipping their own CSS. The tag covers
   the **Livewire** views too, so the reactive screens are themed as well, not just the static
   stubs. Needs `pushery/wirekit` **≥ 2.13** (the countdown component landed there) and
   `@wirekitScripts` in the layout for the live countdown and the withdraw alert-dialog.

   The package's own test suite renders these views against the installed WireKit and fails on any
   component the release lacks, so the published stubs never reach for one your app cannot resolve.

   A withdrawal is irreversible — it appends a `Withdrawn` row to an append-only ledger — so it
   asks first, via a themed alert-dialog rather than the browser's native confirm window.

Every variant bakes in the non-negotiable anti-dark-pattern rules: checkboxes are never
pre-checked (Planet49 C-673/17), a real consent is never `required` (Kopplungsverbot
Art. 7(4)), and the full text is always linked and retrievable (clickwrap, § 305 II BGB).

**Bundled translations.** Every string the package renders — the grace-period banner, the
consent and settings stubs, the re-consent notification, the validation messages, and the
acceptance wording — ships translated in seven locales out of the box: German, English,
Spanish, French, Italian, Dutch, and Portuguese. Override any of them by publishing the
language files:

```bash
php artisan vendor:publish --tag=legal-consent-lang
```

This is independent of `legal-consent.locales`, which is the set of locales you publish your
own legal *documents* in — leave it at `[de, en]` even while the UI is available in all seven.

## Configuration

Everything lives in `config/legal-consent.php`. The keys you are most likely to touch:

| Key | Default | What it does |
|---|---|---|
| `documents` | `[]` | The registry: each key maps to its `legal_basis` (`contract` / `acknowledgement` / `consent`) and `source`. |
| `default_locale` · `locales` | `de` · `[de, en]` | The primary locale and the allowed set (publishing an unlisted locale is refused). |
| `fallback_locale` | `de` | When a document is unpublished in the requested locale, fall back to this one instead of failing. |
| `retention_after_end` | `3 years` | How long proof is kept before `legal-consent:prune` removes superseded/orphaned records. Enforced only once `schedule.prune` is on. |
| `cache.store` · `cache.ttl` | app default · `86400` | Where/how long rendered documents are cached (self-invalidates on a content change). |
| `notifications.channels` | `[mail, database]` | Channels for the change notifications. |
| `notice_periods` | per regime | Advance-notice days per regime/mode. Three keys are wired today — `active_reconsent_min_days`, `deemed_consent_min_days`, and the hard `psd2_min_days`; the other four (`dcd_termination_days`, `p2b_standstill_days`, `eecc_min_days`, `privacy_advance_days`) are reserved for their regimes and not yet read, so tuning them has no effect until those regimes select their own period. A scheduled gating change is measured announcement → effective date; a deemed-consent change is measured announcement → **objection deadline** (the subject must have the full period to object) and is never exempt. Too short a period is refused. A per-document `min_lead_days` in `documents` may **raise** a period, but never undercut a statutory floor: § 675g's two months is hard — neither an override nor this config can talk it down. |
| `durable_medium` | `proof: true`, `channels: [mail]` | Whether the notice dispatch writes an append-only `legal_notices` proof row, and on which durable-medium channels. |
| `schedule.dispatch_notices` · `schedule.close_objection_windows` | `true` · `true` | Whether the two notice sweeps are auto-registered on the scheduler (hourly). |
| `schedule.prune` | `false` | Whether the retention sweep is auto-registered (daily). **Off by default because it deletes** — see Retention. |
| `middleware.allowlist_routes` · `middleware.allowlist_paths` | `[]` · `[]` | Extra route **names**, and extra URI **paths** (wildcards allowed, e.g. `billing/*`), the gate never blocks. The consent route and `logout` are always allowed. |
| `routes.consent_name` · `routes.consent_path` | `legal.consent` · `/legal-consent` | Where to send a subject to act: `consent_name` is a route name (preferred — it survives a path change), `consent_path` the fallback URL. Used by the middleware redirect **and** by the link in every change notice, so a wrong value points the legally-required notice at a dead URL. |
| `routes.api` · `routes.api_prefix` · `routes.api_middleware` | `false` · `legal` · `[api, auth]` | Whether the headless JSON API is registered, under which prefix, behind which middleware. |
| `markdown.html_input` · `markdown.allow_unsafe_links` · `markdown.max_nesting_level` | `strip` · `false` · `20` | CommonMark hardening for rendering document sources. Loosen `html_input` only for sources you fully control — a legal text is rendered into your users' browsers. |

### Optional features (each off by default)

- **`tamper_evidence`** (`false`) — turn on the append-only hash chain. Every new ledger row
  links to the subject's previous one, so a later edit, deletion, or reorder is detectable.
  Audit it with `php artisan legal-consent:verify-ledger` (non-zero exit on a break);
  combine with the DB append-only trigger for the strongest guarantee.

- **`age_gate`** (`enabled: false`, `threshold: 16`) — Art. 8 DSGVO. When on, registration
  additionally requires an `age_confirmed` attestation. The package gates on the
  attestation; verifying the actual age stays your app's job.

- **`tenancy`** (`enabled: false`) — scope documents and consents per
  tenant. Register a resolver in a service provider's `boot()`:

  ```php
  app(\Pushery\LegalConsent\Support\TenantContext::class)
      ->resolveUsing(fn () => auth()->user()?->tenant_id);
  ```

  Each tenant gets its own active version of a `(key, locale)`; admin sweeps (prune,
  dispatch-notices) run across all tenants.

## Retention — turn it on, or nothing is ever deleted

`retention_after_end` (default `3 years`) is a **statement of policy, not an enforcement**. The
sweep that acts on it — `legal-consent:prune` — is the one scheduled task this package does **not**
register for you:

```php
// config/legal-consent.php
'schedule' => [
    'prune' => true,   // daily; off by default
],
```

It is off by default on purpose: it deletes, and an app upgrading into a new version must never
silently start erasing records it has been accumulating. That makes it your decision — but it is a
decision, and not making it means **expired personal data is kept forever** while the config says
otherwise (storage limitation, Art. 5(1)(e) GDPR).

What it deletes is deliberately narrow: only rows past the period that are **superseded** (a newer
row exists for the same subject + document + locale) or **orphaned** (the subject is gone). A
subject's current standing is never deleted by age — that row is the Art. 7(1) proof the live
relationship rests on.

The sweep reports a heartbeat through `LegalConsentMonitor`, so you can alert on it having stopped.
That matters more here than for the other sweeps: a dispatch that stops running leaves visibly
missing mail, while a prune that stops running fails silently — nothing errors, data just stays.

## Upgrading

[UPGRADE.md](UPGRADE.md) is the canonical upgrade guide — the **0.3.x → 0.4.0** steps (new
migrations, removed config keys, removed source drivers, and the now-immutable published rows)
live there in full. The note below covers the earlier, backward-compatible bump.

### 0.2.x → 0.3.0

Backward-compatible: existing code, config, and ledger rows keep working. Run the
migrations and you are done.

```bash
php artisan migrate
```

What they do:

- Add the notice-model columns to `legal_documents` (`notice_mode`, `change_class`,
  `regime`, `notice_period_days`, `offers_termination`, `keeps_unmodified_offered`,
  `objection_deadline`, `objection_closed_at`). **Existing versions are backfilled**: one
  that required re-consent becomes `ActiveReconsent`, one that did not becomes
  `SilentEditorial` — the behaviour they already had.
- Create the append-only `legal_notices` delivery-proof table.
- **Drop the `legal_consents.document_id` foreign key** (PostgreSQL/MySQL only). Its
  `ON DELETE SET NULL` fought the append-only guarantee: deleting a superseded
  `legal_documents` row aborted on PostgreSQL and silently rewrote a ledger row on MySQL.
  The column and the `document()` relation stay; the relation resolves to null once the
  document is gone. Present since 0.1.0 — this is a fix, and nothing you must act on.

Then, optionally:

- `--material` still works; it is now an alias of `--active`. New publishes can classify
  more precisely with `--info`, `--deemed`, or `--editorial`.
- `legal-consent:close-objection-windows` is auto-scheduled (disable via
  `schedule.close_objection_windows`). It only does anything once you publish a `--deemed`
  change.
- `requires_explicit_optin` was removed from the published config's `documents` entries. It
  was never read — the value is derived from `legal_basis`. Delete it from your published
  config if you like; leaving it is harmless.
- **New: `schedule.prune`** (default `false`). Nothing changes for you on upgrade — but if you
  assumed `retention_after_end` was already deleting anything, it was not, and this is the switch
  that makes it true. See [Retention](#retention--turn-it-on-or-nothing-is-ever-deleted).

## Testing

```bash
composer test
```

## Security

Please review the [security policy](SECURITY.md) and report vulnerabilities
privately rather than opening a public issue.

## Built by Pushery

This package is built and maintained by [Pushery](https://www.pushery.com) — a
Berlin-based studio building Laravel applications, SaaS products, and open-source
tools.

Building a Laravel UI? [WireKit](https://wirekit.app), Pushery's open-source
Livewire component kit, gives you a polished component library out of the box.
Browse the rest of our work at [pushery.com](https://www.pushery.com).

## License

The MIT License (MIT). See [LICENSE](LICENSE) for details.
