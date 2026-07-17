# Changelog

All notable changes to `pushery/legal-consent-for-laravel` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and
the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.4.0] - 2026-07-17

> **Breaking (0.x minor).** This release turns the legal texts from a read-only source into an
> admin-maintained, provable record. See [UPGRADE.md](UPGRADE.md) for the migration steps — new
> migrations to run, removed config keys, and removed source drivers.

### Changed

- **Legal texts are now maintained in the app and frozen when published.** A document can be
  authored and reviewed as a **draft** (`'source' => 'drafts'`), then released — and the released
  version becomes immutable proof: the exact sanitized bytes a subject is shown and the exact hash
  the ledger records are one text, because they are one row. Correcting a text is a new version,
  never an in-place edit. A database trigger (PostgreSQL, MySQL and SQLite) plus a model hook reject
  any edit to a published row's proof columns; a direct update now raises `LegalDocumentFrozenException`
  (through the model) or a database error (through raw SQL).
- **The publish gate moved into the resolver.** A document whose draft set is not release-ready can
  no longer be enforced by any path, including the console — the check is where the text is resolved,
  not in a wrapper a caller can skip.
- **The tenant column name is fixed to `tenant_id`.** The `tenancy.column` knob is gone; the column
  is the `TenantContext::COLUMN` constant. Apps that renamed it must rename the column back.

### Removed

- **`sources.database`** and its no-op driver — replaced by the draft store (`sources.drafts`).
- **`sources.cms`** and the closure-resolver path (`CmsResolver`, `ClosureCmsResolver`,
  `CmsAdapterDriver`) — replaced by the draft store and the admin editor.
- **`tenancy.column`** — the tenant column is now the fixed constant `tenant_id`.
- `Content\LegalDocumentManager` — renamed to `Content\LegalSourceRenderer` (`document()` →
  `renderSource()`), so the class name says it renders the *source*, not the published record.

### Fixed

- **The tamper-evidence chain no longer forks under concurrency.** Two consent appends for one
  subject could read the same chain tail and link to the same predecessor — a fork the verifier
  could only report as tampering, training operators to ignore a real alarm. A unique index on
  `(subject_token, prev_record_hash)` (migration `…_000012`) now makes the database reject the
  second writer, and the manager retries against the advanced tail so the loser chains on cleanly
  instead of failing. Proven on real PostgreSQL and MySQL, where the race actually occurs.
- **Enabling tamper-evidence on a subject that already had rows no longer reports a false break.**
  The first chained row was linking to the subject's last *unchained* row, but the verifier's walk
  begins each subject at genesis — so every such subject failed verification. The first chained row
  now starts at genesis, ignoring pre-feature unchained history (which was already unprotected).
- **The WireKit admin stubs now render.** The editor stub named a `<x-wirekit::button-group>` that
  does not exist (the component is `button.group`) and passed the draft body as a slot the editor
  component ignores, so an existing draft rendered empty; the manager stub named `table.heading` /
  `table.cell` (the components are `table.th` / `table.td`). Each is a hard exception the moment the
  view renders. Both stubs are rebuilt against the real components, seed the editor via `:value`, and
  are now render-verified against the installed WireKit — plus a real-browser suite drives the editor
  end to end.
- **The re-consent form now records the real acquisition method.** `ReConsentForm` always wrote its
  ledger row with `SettingsToggle`, mislabeling every gate-driven re-consent's provenance; it now
  takes a `$method` mount parameter (default `ReConsentGate`) so the recorded method matches how the
  consent was actually obtained.
- **The retention period is no longer a promise nothing kept.** `retention_after_end` defaults to
  three years and the docs described it as the point at which records are removed — but the sweep
  that removes them was never scheduled and, unlike the other two sweeps, reported no heartbeat. An
  app could read the config, believe retention was handled, and keep expired personal data forever
  with nothing failing. The sweep is now schedulable (`schedule.prune`), observable, and the README
  states plainly that it is off until you turn it on.
- **The WireKit views are now actually WireKit.** The `legal-consent-wirekit` stubs shipped
  invented `wk-*` CSS classes and TODO comments pointing at a `<wk:…>` tag syntax that has never
  existed in WireKit, and shipped no CSS — so publishing them produced unstyled output while the
  tag reported success. They are rebuilt from real `<x-wirekit::*>` components (callout, checkbox,
  badge, link, stack, heading, button) and inherit the app's theme.
- **The `legal-consent-wirekit` tag no longer skips the Livewire views.** It overrode only the
  three plain stubs, never `livewire/consent-settings` and `livewire/reconsent-form` — the views
  the `ConsentSettings` / `ReConsentForm` components actually render. A WireKit + Livewire app got
  unstyled reactive screens even with the tag set. Both are now covered.
- **A withdrawal now asks before it acts.** The WireKit settings view confirms the irreversible
  withdrawal through an alert-dialog; previously there was no confirmation at all.
- **The WireKit banner drives each deadline with WireKit's live `countdown`** (fed the absolute
  effective/objection instant, never a drifting day count), now that the component shipped in
  WireKit 2.13.0. The WireKit views require `pushery/wirekit` **≥ 2.13**, and a `WireKitViewsTest`
  renders every view against the installed package and fails on any component the release lacks —
  so a view can never again reach for a component the consumer cannot resolve (the bug that briefly
  shipped a develop-only `countdown` tag against WireKit 2.12).
- **The package no longer calls Laravel Foundation helpers it does not depend on.** `config_path()`,
  `database_path()`, `resource_path()` and `lang_path()` exist only in `laravel/framework`, while
  this package requires just focused `illuminate/*` components — so every call declared a
  dependency contract the package does not hold, and would fatal outside a full Laravel app. They
  now go through the `Illuminate\Contracts\Foundation\Application` methods, which
  `illuminate/contracts` genuinely provides. Behaviour is identical.

  The worst offender was not in the service provider but in **`config/legal-consent.php`**, whose
  `sources.markdown.path` defaulted to `resource_path('legal')`. `mergeConfigFrom()` runs in
  `register()`, so that file is loaded on **every** app boot — the publishing calls it sat next to
  only ran under `runningInConsole()`. The key now defaults to `null` and the path is resolved at
  runtime. A `LeanDependencyContractTest` enforces the whole class from now on: this gap is
  invisible in development, because testbench pulls `laravel/framework` into the vendor tree and
  composer's `replace` map makes `illuminate/contracts` resolve to the framework's own copy.

### Added

- **`schedule.prune`** (default `false`) — auto-registers the daily retention sweep. It is off by
  default because it deletes, so an app upgrading into this version never silently starts erasing
  accumulated records. Turning it on is what makes `retention_after_end` an enforced period rather
  than a statement.
- `legal-consent:prune` now reports a `LegalConsentMonitor` heartbeat, like the other two sweeps —
  so an operator can alert on it having stopped. That matters most here: a dispatch that stops
  running leaves visibly missing mail, while a prune that stops running fails silently.
- `ui.withdraw_confirm_title`, `ui.withdraw_confirm_body` and `ui.cancel`, translated in all seven
  bundled locales.
- **A draft store for legal texts.** Documents set to `'source' => 'drafts'` are authored per
  locale, carry a review state, and are released as a set. A source-hash tracks when a reviewed
  translation has drifted from the text it was translated from (staleness), so a release cannot
  quietly ship an out-of-date locale.
- **`LegalDraftSaved` and `LegalDraftReviewed` events** — dispatched when a draft's text changes and
  when a human signs off on its exact bytes. Each carries an opaque actor string the consumer logs
  under its own retention policy; the package deliberately never stores the editor identity itself.
- **Two admin screens, opt-in and fail-closed**, shipped as publishable Blade stubs (plain and
  WireKit): a manager that releases every locale of a document atomically, and a per-locale editor.
  Both require a Gate ability named in `admin.ability` and return `404` when it is unset or denied —
  there is no ungated publish path. The editor sanitizes on store, so its preview is the exact bytes
  a publish freezes.
- **`Consent::published($key, $locale)`** — the one read path for a public legal page. It returns
  the frozen row's exact bytes and hash (or `null` before a locale is published), and never falls
  back to another locale, so the page a subject reads and the ledger's proof can never diverge.
- **`Consent::registrationChecklist($locale)`** — returns the consent controls a registration form
  must render as `Support\RegistrationChecklistItem` value objects, derived from what is actually
  published, so a form stops hardcoding its own checkbox list and can never block on a document that
  no longer exists or omit one that does. Each item's `required` follows the legal basis (a real
  consent is never required — Art. 7(4)), never a UI decision.
- **An AI-translation seam.** Bind `Contracts\LegalTextTranslator` to enable the editor's
  "Translate" action; unbound, it reports that no translator is configured. A machine translation is
  always produced unreviewed and must be reviewed by a human before it can be released.
- **`legal-consent:verify-documents`** — verifies every published row against its stored hash and
  flags notice-mode or wording-locale divergence, so tampering or drift is detectable from outside.
- **`admin.ability`**, **`sources.drafts`** and **`cache.enforceable_ttl`** configuration keys.
- **`UPGRADE.md`** — a migration guide for breaking releases, starting with `0.3.x → 0.4.0`.

## [0.3.0] - 2026-07-16

### Added

- **Change-class notice model.** A change to a legal text now carries a first-class notice mode
  — `SilentEditorial`, `InfoPush`, `DeemedConsent`, or `ActiveReconsent` — so it gets exactly the
  communication the law requires:
  - **Info-only changes** (`--info`): a material change that must be *announced* but requires no
    action and never blocks access — a payment-contract update under § 675g BGB, a material
    privacy-notice change, a reference-rate change. Delivered as a `LegalChangeInformational`
    notification ("no action required").
  - **Deemed consent** (`--deemed`): a minor/peripheral contract change where silence may bind,
    with an objection window and the § 308 Nr. 5 lit. b warning (`DeemedConsentNotice`). The new
    `legal-consent:close-objection-windows` command records a deemed acceptance once the window
    closes with no objection.
  - **Active re-consent** (`--active`, the successor to `--material`) keeps the existing
    hard-gated re-consent behaviour.
- **Objection and free-termination recording** — `ConsentManager::object()` and `::terminate()`
  (with `ConsentObjected` / `ConsentTerminated` events and JSON + Livewire surfaces) so a
  subject's objection (§ 308 Nr. 5 / Art. 21) or free termination (§ 675g / § 327r) is provable.
- **Durable-medium delivery proof** — an append-only `legal_notices` table recording that a
  change notice was delivered to a subject, in what form, in which locale, with its exact
  content (CJEU C-375/15). `legal-consent:prune` covers it on the same retention rule as the
  consent ledger, so the proof honours storage limitation (Art. 5(1)(e)) without ever deleting
  the notice behind a subject's current standing.
- New public enum cases: `ConsentAction::Objected`, `::Terminated`, `::DeemedAccepted`, and
  `ConsentMethod::DeemedAcceptance`. They surface in the `action`/`method` columns, the JSON
  API, and the Art. 15 `history()` export — a consumer exhaustively `match`ing on either enum
  should add arms for them.
- New `SendsNoticeMail` contract, implemented by all three change notifications, so a custom
  notification can carry the mandatory-content self-check the delivery proof records.
- **Per-regime advance-notice periods** (`notice_periods` config) — a scheduled gating or deemed
  change is validated against the minimum lead for its regime, with a per-document override.
- Three banner variants via `ConsentBanner::forSubject()`: the re-consent countdown, the info-only
  heads-up, and the deemed-consent objection window. Copy for all of the above ships in the seven
  bundled locales.

### Fixed

- A material privacy-notice change is now announced without blocking access — a privacy notice is
  acknowledged, not gated, so it is published info-only and never traps the user (WP260 rev.01).
- A change that is neither editorial nor a re-consent is now actively communicated on a durable
  medium instead of taking effect silently.
- Deleting a superseded `legal_documents` row no longer breaks the consent ledger. The
  `legal_consents.document_id` foreign key's `ON DELETE SET NULL` collided with the append-only
  guarantee: on PostgreSQL the delete aborted, on MySQL it silently rewrote a ledger row that is
  promised to be immutable. The constraint is dropped (the column and its `document()` relation
  stay); every row already proves itself through its denormalized document snapshot. Present
  since 0.1.0 — apps on PostgreSQL or MySQL should run the new migration.

### Changed

- `legal-consent:dispatch-notices` now routes a due change to the notification matching its notice
  mode. `legal-consent:publish` gains `--info`, `--deemed`, `--active`, `--regime`, `--change-class`,
  `--objection-at`, `--offers-termination`, and `--keeps-unmodified`; `--material` remains as the
  alias of `--active`. All existing behaviour, config, and the `requires_reconsent` column are
  preserved — the release is backward-compatible.

## [0.2.0] - 2026-07-13

### Added

- Translations for the user-facing strings (settings/banner UI, re-consent notifications,
  validation messages, acceptance wording) in five more locales — Spanish, French, Italian,
  Dutch, and Portuguese — bringing the shipped set to seven (de, en, es, fr, it, nl, pt).
  Each locale preserves the contract-vs-notice distinction (a privacy policy is acknowledged,
  never "consented to") and the pluralised grace-period countdown. The consuming app's
  document-locale set (`legal-consent.locales`) is unchanged and still defaults to `de`, `en`.

## [0.1.1] - 2026-07-11

### Fixed

- README PHP-version badge now uses the reliable `packagist/dependency-v` shields endpoint;
  the previous `packagist/php-v` route was rendering "not found".

## [0.1.0] - 2026-07-11

### Added

- Append-only consent ledger (`legal_documents` + `legal_consents`) with a polymorphic
  subject, denormalized proof snapshots, a portable append-only guard (app-layer plus
  Postgres/MySQL triggers), and one-active-version enforcement.
- `DocumentType` / `ConsentAction` / `ConsentMethod` enums separating contract acceptance,
  notice acknowledgement, and consent.
- Interchangeable content sources: Markdown files (default), database, and a foreign-CMS
  adapter, behind a render pipeline with a DOM-based HTML sanitizer.
- Versioning with content-hash change detection, human-classified materiality, and a
  `major_version` re-consent gate.
- `ConsentManager` + `HasLegalConsents` trait + `Consent` facade (record / accept /
  withdraw / outstanding / status / history), with server-side `ConsentContext`.
- `EnsureLegalConsent` middleware (redirect or `409 legal_consent_required`) and a
  non-blocking grace-period banner.
- Fortify-optional registration integration: a trait, a `Registered` listener, and an
  opt-in headless JSON API.
- Delayed-notice pipeline: a 60-day material lead-time invariant, the idempotent
  `legal-consent:dispatch-notices` sweep, the queued `ReconsentRequired` notification, an
  auto-registered hourly schedule, and a monitoring seam.
- Console commands: `publish`, `check-drift`, `dispatch-notices`, `prune`, `cache-flush`,
  `verify-ledger`.
- Optional tamper-evidence: an append-only, per-subject hash chain (`tamper_evidence`) with
  the `legal-consent:verify-ledger` audit command.
- Optional age gate (`age_gate`) — an Art. 8 DSGVO minimum-age attestation on registration.
- Optional multi-tenancy (`tenancy`) — per-tenant documents and consents via a `TenantContext`
  resolver, with cross-tenant admin sweeps.
- Optional reactive UI: opt-in Livewire components (`ReConsentForm`, `ConsentSettings`) that
  register only when Livewire is present, plus a WireKit-flavored stub variant.
- Multi-language completeness: fully localized UI stubs, a pluralized grace countdown, a
  consumed `fallback_locale`, and locale validation on publish.
- Publishable config, de/en translations, and optional framework-agnostic Blade UI stubs.
