# Changelog

All notable changes to `pushery/legal-consent-for-laravel` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and
the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
