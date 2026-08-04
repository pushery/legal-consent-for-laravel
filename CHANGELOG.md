# Changelog

All notable changes to `pushery/legal-consent-for-laravel` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and
the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.11.0] - 2026-08-04

Only the optional WireKit view variants are affected. If you publish the plain stubs, write
your own markup, or use no UI at all, nothing in this release reaches you: no migrations, no
config keys, no change to the PHP API.

### Changed

- **The WireKit view variants now announce WireKit's own screen-reader strings in German, and
  the documented floor moves to `pushery/wirekit` ≥ 2.26.0.** Inside those views a few strings
  belong to WireKit rather than to this package — the external-link hint on the full-text link,
  the sr-only prefix on the admin policy notice, the dismiss label. They ran through `__()` with
  the English text as the key, and WireKit shipped no catalog of its own, so a German consent
  surface announced `(opens in new tab)` and `Notice:` to a screen reader while every visible
  word around them was German. WireKit 2.26.0 ships and registers `de`, and it arrives with no
  publishing step and no configuration.

  Nothing renders differently to a sighted reader, which is exactly why this went unnoticed
  across three releases: no visible text changes, no test that reads the page for meaning fails,
  and the only person affected is the one who cannot see the screen — on the surface where a
  person is deciding something legally binding. The package's test suite now asserts the German
  announcement in the rendered markup, so it cannot regress silently.

  **Two of this package's seven bundled locales are covered.** WireKit's catalog is `en` and
  `de`; in `es`, `fr`, `it`, `nl` and `pt` those strings are still announced in English. That is
  measured in the rendered stub rather than inferred, and it is not something this package can
  fix for you: the keys are JSON *string* keys and therefore application-global, so shipping
  translations for them here would silently retranslate every other WireKit component in your
  app. [The UI documentation](https://docs.pushery.com/legal-consent-for-laravel/user-interface)
  shows where to put them in your own `lang/{locale}.json`.

  Below 2.17.1 two older breakages still apply and are unchanged: the admin editor loses
  everything typed into it, and the manager table cannot emit a row header.

## [0.10.0] - 2026-08-03

### Fixed

- **The package now declares every Laravel component its code imports.** It uses focused
  `illuminate/*` components rather than the full framework, and four of them were missing from
  the manifest: `illuminate/auth`, `illuminate/cache`, `illuminate/http` and
  `illuminate/routing`. An install without `laravel/framework` therefore resolved a dependency
  set the code could not run on and failed with a class-not-found the moment the service
  provider booted. `illuminate/log` is named too — the `Log` facade is called on two shipped
  paths and no other declared component pulls it in — and `illuminate/bus` and
  `illuminate/collections` were reachable through other components but undeclared. Nothing
  changes for an application that already has the full framework.

- The migration that freezes published legal texts refuses to build its trigger from a column
  name that is not a plain SQL identifier. The MySQL and SQLite variants have to list the
  protected columns by name, and they read that list from the database's own catalog — safe in
  practice, but "it cannot be hostile" was an assumption rather than something the code checked.
  It now stops before a single character of an unexpected name reaches a statement.

- The optional v1 backfill migration no longer coerces values it cannot trust. It read the
  authenticatable model and the default locale out of configuration as strings without checking,
  so an application whose auth configuration holds something else would have written the literal
  `Array` into `subject_type` for every imported row; both now fall back. It also cast a raw
  database id to an integer, and PHP turns a non-numeric value into `0` — which attaches a
  stranger's acceptance to whichever user has that id. Only an integer, or a string of digits
  that survives the round trip, is imported now; anything else leaves its row behind for the
  operator to see. And `getMorphClass()` is no longer called on any class that merely exists,
  which aborted the backfill on a non-Eloquent model before it wrote a single row — so that one
  failure, unlike the two above it, left nothing behind to clean up.

- **Accepting a page that binds nobody is refused instead of failing.** An `informational`
  document carries no acceptance sentence, and the two entry points that append an acceptance —
  `Consent::accept()` and `Consent::record()` — had no check for the class. `accept()` reached the
  fingerprint helper, which now refuses such a document, and the untyped failure left the JSON API
  as a `500`; `record()` reached the insert, where the ledger's wording snapshot is `NOT NULL`, and
  the caller got a raw database integrity error. Both now throw `NotConsentBearingException` before
  anything is written, and `POST /legal/consent` answers `422` with an `error` of
  `not_consent_bearing` — the sibling of `not_withdrawable`, `not_objectable` and `not_terminable`.
  The package's own default registry ships such a document, so this was reachable with a key no
  consumer typed.

- **An informational page no longer freezes an acceptance sentence it never asked for.**
  `ui_wording` is the exact sentence a subject clicked: it cannot be changed after publish, it is
  copied verbatim into every ledger row, and it is folded into the hash chain. An `informational`
  document — an Impressum, a cookie policy, an accessibility statement — asks the reader for
  nothing, but the render pipeline had no branch for it and fell through to the default sentence,
  so publishing one froze "Ich habe die Bedingungen gelesen und akzeptiere sie." into a page that
  binds nobody, permanently. Such a document now carries no sentence at all, and a wording
  supplied by its source is dropped rather than stored.

### Changed

- `LegalDraftSaved` and `LegalDraftReviewed` no longer use the framework's `Dispatchable` trait.
  Both are dispatched with `event(new …)` and the trait only added static `dispatch()` helpers,
  which nothing called, while being the one imported symbol with no standalone component behind
  it. If you dispatched either event with `LegalDraftSaved::dispatch(…)`, use
  `event(new LegalDraftSaved(…))`.

  To be precise about what this does and does not buy: the component manifest now matches what
  the code imports, but a genuinely framework-free install is still out of reach — shipped code
  calls fifteen global helpers that only the full framework defines. That is an open question
  about what this package should promise, not an oversight, and it is tracked.

- **`Consent::object()` and `Consent::terminate()` refuse the document classes they cannot apply
  to**, the way `withdraw()` always has. An objection now reaches a contract (a Widerspruch under
  § 308 Nr. 5 lit. a BGB) and a privacy notice (Art. 21) but not a consent — which is withdrawn,
  not objected to — and a termination reaches only a contract. A refused call throws
  `NotObjectableException` or `NotTerminableException`. This matters because the ledger is
  append-only: a row asserting a state that does not legally exist cannot be corrected, and every
  later reader takes it as proof.
- `ui_wording` is nullable — **one new migration ships, so run `php artisan migrate`** — and
  `Document::$uiWording` / `PublishedDocument::$uiWording` are `?string`. Only an `informational`
  document has none; every other class still fails loudly when no sentence resolves. Code that
  renders the wording of an arbitrary document needs a null check — see the upgrade notes.
- **The migration that freezes published legal texts now REFUSES an engine it cannot protect —
  and that includes MariaDB.** PostgreSQL, MySQL and SQLite each get a trigger; anything else used
  to migrate successfully and leave `legal_documents` with no trigger on it and no signal anywhere
  — the immutability this package builds its evidentiary weight on, silently absent. A migration
  that stops is recoverable; a proof table that only looks frozen is not.

  Name the engines, because "MySQL gets a trigger" does not cover the one that reads like it does:
  Laravel returns the driver name from your connection configuration verbatim and ships `mariadb`
  as its own driver, so a MariaDB connection is never seen as `mysql`. MariaDB, SQL Server
  (`sqlsrv`) and any custom driver are refused. This reaches **existing** installations, not only
  new ones — the migration added in this release re-installs the trigger, so an upgrade is where
  a fourth engine finds out. The refusal is raised before anything is altered, so a refused
  `migrate` leaves the schema untouched rather than half-applied. The upgrade notes give the
  operator's options.
- The JSON API answers a refused objection or termination with `422` and an `error` of
  `not_objectable` / `not_terminable`, the way `POST /legal/withdraw` always has. Without it a
  refusal reached the client as a `500` — an "our fault, retry" for a request that is simply not
  a thing.
- **A `document_key` that is not published is answered as the client error it is, on every
  surface.** The JSON API returns `404` with an `error` of `unknown_document`; the Livewire
  components abort with `404`. Both used to surface as a `500`, which tells a client "our fault,
  retry" — on endpoints that append to a ledger — and files a consumer's typo as an outage of this
  package in their monitoring. It is not only a typo that gets there: a document unpublished
  between the render and the click reaches the same path from a legitimate button. If you check
  for `500` on these endpoints today, check for `404` instead.
- **The Livewire components answer a transition a document's class cannot carry with `404` too**,
  rather than letting the manager's refusal surface as a `500`. The refusal itself is unchanged —
  it is what keeps rows asserting legally impossible states out of an append-only ledger. What the
  subject sees is a `404` with no detail: the sentence names the document key and its legal class,
  which is the template author's problem and none of the subject's business. It rides on the
  exception, and since Laravel never reports a `NotFoundHttpException` it reaches your log and your
  error page just as little — report `404`s yourself if you want to see these. The JSON API keeps
  `422` here, because a machine client can act on the difference between "no such document" and
  "not that kind of document".
- **The re-consent form no longer breaks when a document is unpublished mid-flight.** `submit()`
  treats a document that has stopped being published exactly as it treats one that changed: it
  clears the stale ticks and asks the subject to review what is actually current. It used to be a
  `500` on the primary button of the re-consent gate — and because the enforcement middleware keeps
  gating, a subject who hit it could neither clear the gate nor get past it. The window is wider
  than it sounds: the outstanding list is served from a cache while the acceptance re-resolves
  against the database.
- **`GET /legal/status` answers `{}` instead of `[]` when nothing is published.** The payload is a
  map keyed by document key, but PHP cannot tell an empty map from an empty list, so the empty case
  serialized as a JSON array — and a client that decodes it into a dictionary failed on exactly the
  response that carries no other sign of trouble. The populated shape is unchanged.
- `DefaultConsentManager::acceptanceFingerprint()` refuses an informational document instead of
  hashing an empty sentence. Nothing accepts such a page, so nothing needs to fingerprint one.

### Added

- **The settings component can switch off the transitions your product has no answer to.**
  Livewire dispatches to any public method of an embedded component, so removing a button from a
  published view never switched anything off — `<livewire:legal-consent.consent-settings
  :allow-objection="false" :allow-termination="false" />` does. A disabled action returns `404`,
  and both flags are locked server-side.
- Headings for three conventional informational document keys — `imprint`, `cookies` and
  `accessibility` — in all seven bundled locales. Rename or remove them freely; they are
  conventional keys, not reserved ones.

### Documentation

- The French withdrawal-confirmation copy uses the informal register the other six locales use.
  It was the one string in `lang/fr` that addressed the reader formally.

- `README.md` no longer tells a reader to run the test suite. Neither the suite nor the PHPUnit
  configuration is part of the published package, so that command had nothing to run from an
  installed copy — the same defect fixed in `CONTRIBUTING.md` below, on the surface that was
  missed. It now states the quality bar as a fact and points at the development repository.

- `CONTRIBUTING.md` no longer prescribes commands that cannot run from the published package. Five
  of the six it listed need a configuration file that is deliberately not shipped, so the third
  step failed with "no error configuration file found" for anyone who followed it. It now states
  the quality bar as a fact about how the package is maintained, and adds the local PHP
  requirement — 8.4.1 for development, while the package itself installs on 8.4.0.

## [0.9.0] - 2026-07-26

### Changed

- **The published WireKit views now require `pushery/wirekit` ≥ 2.17.1** (previously ≥ 2.13).
  They were relying on capabilities that arrived in that release, and below it two things fail
  without saying so: the admin editor's binding lands on the wrapper instead of the textarea, so
  everything typed into it is lost on save, and the manager table cannot emit a row header, leaving
  every status cell without a programmatic association to its document (WCAG 1.3.1). If your app
  publishes `--tag=legal-consent-wirekit`, upgrade WireKit before taking this release.

- The WireKit admin editor binds with a plain `wire:model` and declares its own toolbar, instead of
  the imperative `$wire.set` binding and WireKit's `basic` preset it used while those paths were
  unreachable upstream. The toolbar set is now this package's decision rather than whatever the
  preset happens to offer — every command in it produces markup the sanitizer keeps, so an admin
  can no longer format a clause that silently disappears on save.

### Fixed

- The WireKit manager table heads each row with the document it describes (`<th scope="row">`), so
  a screen reader announces "terms" with the status cell instead of leaving a bare "not written"
  adrift in a grid (WCAG 1.3.1). The plain stub always did this; the WireKit variant could not,
  because the component could only emit `scope="col"`.

### Documentation

- How to get WireKit's own screen-reader strings — `(opens in new tab)`, an alert's `Notice:`
  prefix, `Dismiss` — into a non-English locale. They are translatable but ship English-only, and
  WireKit's reference list publishes to a path Laravel's JSON loader does not read, so the working
  answer (your app's `lang/{locale}.json`) is now written down. This package deliberately does not
  ship those keys: JSON string keys are app-global, so defining them here would retranslate every
  other WireKit component in your app.

## [0.8.0] - 2026-07-26

### Added

- A fourth document class, `informational`, for a page you must **publish** but which binds
  nobody: an Impressum (§ 5 DDG), a cookie policy, an accessibility statement. It uses the same
  machinery as everything else — the draft editor, the review gate, the translation seam, the
  sanitizing pipeline, the frozen published row — and carries none of the consent semantics: it
  never appears in the registration checklist, never writes a ledger row, never gates access, and
  never sends a notice. Publish it with `--editorial`; the other modes are refused, because they
  each describe an audience that does not exist for a page nobody accepts.

  It is also the one type with a locale fallback on the read path: a missing translation falls
  back to your `default_locale`. Everything a subject agrees to keeps the strict behavior — a
  contract published only in `de` still returns `null` under an `en` URL, because showing one
  language's contract under another's is the substitution this package exists to prevent. An
  Impressum has no such risk and a blank page fails the duty to be reachable.

  Register it with `'legal_basis' => 'informational'`.

### Changed

- `LegalDocumentReleaser` no longer takes a `TenantContext` — it never used it. Resolve the class
  from the container (`app(LegalDocumentReleaser::class)`) and nothing changes; only code that
  constructs it by hand with three arguments needs the third one dropped.

### Fixed

- Reclassifying a **binding** document as `informational` is refused. A contract or privacy
  notice that subjects have been asked to accept cannot be republished under a basis that binds
  nobody: it would silently remove the gate while their recorded acceptances stayed on file, and
  nothing would look wrong. Publish it under its existing basis, or register the page under a new
  key. The reverse direction stays open — becoming stricter is always safe.
- The notice banner ignores informational pages, so a row marked as an info push — by hand or by
  a restored dump — cannot put "please take notice" in front of every visitor for a page that asks
  them for nothing.
- `statusFor()` no longer reports an informational page as permanently outstanding. It computes
  that field from `! requires_explicit_optin`, which is the same value a contract carries, so a
  page nobody accepts sat at `accepted_major = 0` forever — a row a "your agreements" screen would
  render and no subject could ever satisfy.

## [0.7.0] - 2026-07-26

### Added

- `legal-consent:doctor` reports how a **published** `config/legal-consent.php` differs from the
  package's own, and changes nothing. Publishing freezes a copy, and `mergeConfigFrom()` is a flat
  merge: a whole new top-level block reaches you, but a key added *inside* a block your published
  file already declares never arrives — at runtime it is not undocumented, it is gone, with the
  package default replaced by an older file that never heard of it. The reverse rots too, and a
  stale entry naming a class that has since been removed fails when something resolves it, pointing
  at your config rather than at the upgrade. The command names both. It exits non-zero only for
  keys that never arrive, so it is usable as a CI check; your `documents` registry is left alone in
  both directions, because curating it is your call.
- `PublishedDocument::acceptanceFingerprint()` — the value the accept-time guard compares against,
  now reachable from the documented read path. Previously it existed only for the Eloquent model,
  so a consumer rendering its own legal page had to rebuild the hash by hand (a second
  implementation, which is exactly what makes a guard stop being one), record `contentHash` alone
  (it covers the sanitized body, **not** the acceptance sentence the subject read), or bypass the
  DTO. `DefaultConsentManager::acceptanceFingerprint()` accepts both types now, and the DTO method
  delegates to it, so there is still one implementation.
- `PublishedDocument::$tenantId`, so a multi-tenant consumer can confirm which tenant's text it is
  holding. Reads were already confined to the current tenant by the model's global scope; this is
  the part that lets you check rather than trust.

### Fixed

- An **empty** acceptance sentence can no longer be published. The translation branch of
  `resolveWording()` only checked whether the translator returned something other than the key,
  which catches an absent translation but not `'terms' => ''` in published lang files. That matters
  because `ui_wording` is frozen proof — it is copied verbatim into every consent row and folded
  into the hash chain, and the immutability trigger refuses to change it afterwards — so an empty
  sentence was permanent, and rendered as a required checkbox with no accessible name: a submit
  blocker whose cause was invisible. Both branches now require a non-empty sentence and otherwise
  fall through to `MissingAcceptanceWording`, as designed.

### Changed

- The full documentation moved to <https://docs.pushery.com/legal-consent-for-laravel/> and the
  README is now a showcase that links to it. Every section it used to carry — installation, the
  publish tags, recording consent, the four notice modes with a worked example each, content
  sources, the admin screens, the UI levels, retention, and the complete configuration and
  command reference — is on the portal, restructured rather than shortened. The README keeps what
  introduces the package: what it does, how to install it, and where to read the rest.
- README links to `art/header.png` and `UPGRADE.md` are absolute URLs on the repository now.
  Both paths are `export-ignore`d from the Composer dist, so the previous relative links
  resolved on GitHub but not from an installed package.
- Shipped prose — docblocks, inline comments, the Blade stubs, the translation files and the repo
  meta documents — is US English throughout, and a test now holds it there. Nothing behavioral
  changed: the ARIA `aria-labelledby` attribute and the `analyse` command names are identifiers,
  not prose, and are untouched.

### Removed

- The `docs/` directory is no longer part of the public SHIP allowlist. It was announced here but
  never reached a release, and documentation is now published through the portal instead — a
  second, public copy of the same pages would only drift from it.

## [0.6.0] - 2026-07-22

Deep-audit hardening. **One breaking change:** the tamper-evidence chain serialization is now
injective, which changes every computed chain hash — a chain written by any earlier version no longer
verifies, and UPGRADE.md documents the one-time chain reset. Everything else is additive or a fix: the
registration path can opt into the accept-time guard the re-consent form already had, the legal-notice
sweep is resumable and batched, the registration trio resolves through one locale chain, and a
repeated identical status message is announced instead of passing in silence.

### Added

- **An opt-in accept-time guard for the registration path.** A registration checklist item now exposes
  `contentHash` (the render-time fingerprint) and `hashField()` (`legal_{key}_hash`); render that hidden
  input — the `consent-checkboxes` stub does it automatically from an item's `->toArray()` — and a
  version published between page load and submit is caught (`DocumentChangedException`) instead of
  silently freezing a text the visitor never saw, the same guarantee the re-consent form gives. It is
  opt-in: a form that does not render the field keeps the prior behavior exactly.

### Changed

- **The legal-notice sweep is resumable and batched.** `legal-consent:dispatch-notices` processes
  affected subjects in chunks — one notification `send()` call and, the actual round-trip saving, one
  pseudonym-token lookup per ledger instead of two per subject (the queued notification jobs and the
  proof-row inserts stay one per subject). When durable-medium proof is on, a run
  killed or overtaken mid-sweep (the 120-minute overlap lock can expire on a large population) now
  resumes on the subjects still owed a notice and does not write a second proof row for one already
  notified — delivery stays at-least-once (no unique constraint), but the bulk of the duplication a
  lock-expiry overlap used to cause is gone.
- **BREAKING (tamper-evidence): the ledger hash-chain serialization is now injective.** The canonical
  form that feeds each row's `prev_record_hash` collapsed `null`, `''` and a `false`/`0` to the same
  bytes and joined fields with an unescaped `\x1f`, so two distinct rows could share a hash — a forged
  row could be crafted to collide onto a real one. Each field is now emitted self-delimiting
  (`N` for null, else `S<len>:<value>`). This **changes the computed hashes**, so any tamper-evidence
  chain written by an earlier version no longer verifies after upgrading — the previous `\x1f` format
  was byte-identical across **v0.1.0–v0.5.0**, so every prior release is affected, not only v0.5.0
  (`legal-consent:verify-ledger` will report a break). This is a deliberate pre-1.0 correctness break;
  if you enabled `tamper_evidence` on any prior version with persisted rows, treat this as a chain
  reset — see UPGRADE.md for the re-baseline steps.
- **The registration checklist order is pinned to engine-independent byte order.** Keys are sorted
  with `SORT_STRING` so the checkbox order never depends on the database collation or on numeric
  coercion — a package-defined order rather than one inherited from the environment.

### Fixed

- **The notice sweep uses the portable keyset seek on MariaDB and unknown drivers.** The sargable
  row-value tuple seek is now opt-in for PostgreSQL and SQLite — the engines proven to range-scan the
  composite index. MariaDB (which reports its own driver name, never `mysql`, since Laravel 11),
  MySQL, SQL Server and any unknown driver keep the portable OR-form seek, so a large affected
  population no longer silently degrades to a non-sargable plan (or invalid syntax) on those engines.
- **The WireKit admin editor now announces a stale-source warning.** Its stale-source region is
  always present in the DOM with only the text gated (matching the plain stub), so a staleness that
  flips true as the result of a save is announced by the screen reader instead of being inserted
  together with its text, which is never spoken (WCAG 4.1.3).
- **The v0.5.0 "banner render costs zero queries" claim is store-qualified.** It holds on a non-DB
  cache store (redis/memcached/file/array); on the framework-default `database` store each active-set
  lookup is itself a cache-table read, so the reads move to the cache table rather than disappearing.
  Point `LEGAL_CONSENT_CACHE_STORE` at a non-DB store for the full saving.
- **A repeated identical status message is re-announced (WCAG 4.1.3).** The re-consent form, the
  "my consents" screen and the admin manager and editor now bump a monotonic nonce on every status
  write, so a message set twice (a re-consent race firing twice, a repeated save) re-announces in the
  `aria-live` region instead of staying silent. A re-consent submit with nothing ticked now shows a
  prompt (new string in all seven locales) instead of reading as a dead no-op.
- **Registration resolves its rules, checklist and recorded row through the same locale chain.** A
  mandatory document published only in the configured `fallback_locale` (not the default) is now
  validated and displayed, not merely recorded — all three sides resolve
  `seen → fallback_locale → default_locale` identically, so the form can never require or record a
  document it did not show.

### Security

- **The opt-in "return to intended URL" now refuses a cross-origin target.** When
  `legal-consent.routes.return_to_intended` is enabled, a cleared re-consent gate returns the subject
  to `url.intended`. That value is derived from `redirect()->guest()`, which can fall back to the
  `Referer` header, so a poisoned external (or protocol-relative) origin is now dropped for the
  configured home route — the post-consent redirect can no longer be turned into a phishing hand-off.
  The same-origin check also drops the shapes a browser resolves but `parse_url` does not: a
  backslash authority (`/\host`) and a leading-control-character prefix.
- **The re-consent form fails closed when a ticked document has no render-time hash.** A document
  ticked while momentarily not outstanding (its fingerprint never captured) that becomes outstanding
  by submit no longer skips the accept-time guard — it clears the ticks and asks for a review instead
  of recording an acceptance of a version whose text was never rendered.

## [0.5.0] - 2026-07-21

Two changes alter existing behavior — both deliberate, both about the proof being right rather than
merely present. The ledger models are no longer mass-assignable (write through `forceCreate()` /
`forceFill()` if you wrote rows directly), and registration now derives its rules, its checklist and
its recorded row from the same resolution, so the consent section stays dormant until you publish.

### Added

- **A bundled Laravel Boost skill.** `resources/boost/skills/legal-consent-for-laravel/SKILL.md`
  ships adoption guidance Boost surfaces inside consuming applications — install, publish a version,
  enforce the gate, read the frozen row, and the anti-patterns that silently break the proof. The
  release pipeline refuses to publish without it, or while the scaffold placeholder is still present.
- **An umbrella publish tag.** `php artisan vendor:publish --tag=legal-consent` publishes the
  standard set — config, migrations, views and lang — in one command, while every group stays
  individually addressable. Three groups stay deliberately outside it, because publishing them
  unasked would be destructive or contradictory: `legal-consent-users-cache` (drops a column from
  your `users` table), `legal-consent-backfill`, and `legal-consent-wirekit` (overwrites the plain
  view stubs). The README shows both forms, and the package now carries a Laravel version badge.
- **The enforcement gate takes a configurable subject predicate.** `legal-consent.gate.subject_filter`
  — a `fn (Model): bool`, or an invokable class-string which stays `config:cache`-safe — scopes which
  authenticated subjects the gate blocks, so it can be ordered after the app's own
  verification / onboarding gates instead of overtaking them (e.g. not asking an unverified user for
  legally-binding consent first). Null keeps the default of gating every authenticated subject; a
  misconfigured predicate fails safe (the subject stays gated).
- **Accept-time content-hash guard against a mid-session release (Art. 7(1)).** `Consent::accept()`
  takes an optional `$expectedContentHash` — the hash the subject was shown, captured at render. If
  the active document has since been re-released to different content, acceptance is refused with a
  `DocumentChangedException` (the headless API returns `409 document_changed`) instead of freezing a
  version the subject never read. The bundled re-consent form captures the hash at render and
  re-shows the current text on a mismatch; passing no hash keeps the prior behavior.
- **The tamper-evidence chain can be HMAC-keyed.** Set `legal-consent.tamper_evidence_key` (from
  `LEGAL_CONSENT_TAMPER_KEY`, held outside the database) and each ledger row is hashed with
  HMAC-SHA-256 instead of a bare SHA-256, so an actor with only table-write access — who does not
  hold the secret — can no longer **re-chain** an edited row into a chain the verifier reports as
  intact. Unset keeps the legacy unkeyed hash; the secret must be fixed before the first chained row,
  because append-only rows cannot be re-keyed.

  **What keying does not close, stated plainly:** the chain root is still a public constant and each
  row stores only the link to its predecessor, never its own hash. So an attacker who can INSERT can
  still replace — not merely truncate — the newest row of any chain. (The other half, *fabricating a
  whole new chain* for a subject under a fresh token, is now caught: see the verifier entry below.) Keying raises the bar for editing existing history; it does not make the ledger
  unforgeable. Treat the append-only database trigger (and restricting INSERT to the application role)
  as the primary defense, and read `verify-ledger`'s "intact" as "no evidence of re-chaining", not as
  proof of authenticity.
- **The re-consent gate can return a deep-linked subject to where they were headed.** Opt-in via
  `legal-consent.routes.return_to_intended` (off by default): the enforcement middleware now stashes
  the intercepted URL as the intended target, and once a subject clears every outstanding document in
  the re-consent form they are redirected back to it, falling back to `legal-consent.routes.home`. A
  settings-page embed and a partially-completed gate never redirect, so existing behavior is
  unchanged unless you opt in.

### Changed

- **Registration rules, the displayed checklist and the recorded proof now resolve identically.**
  The validation rules came from the config registry while the checklist read published rows and the
  recorder fell back to the default-locale version of a mandatory document — three answers to one
  question. A registration could therefore require a checkbox for a document that was not published,
  omit a control whose acceptance was then recorded anyway, or record a version it never displayed.
  All three sides now use the recorder's resolution: configured keys intersected with the ACTIVE
  rows, with the default-locale fallback for mandatory documents only (a voluntary consent may never
  be required, Art. 7(4)). Two consequences worth knowing: the consent section is **dormant until you
  publish** — an unpublished document demands nothing — and a document's legal nature is read from
  the published row rather than the config entry, so a drifted `legal_basis` can no longer decide
  whether a checkbox is mandatory. The age-gate attestation is unaffected; it is about the person,
  not a document.
- **The append-only ledger models are no longer mass-assignable.** `LegalConsent`, `LegalNotice` and
  `LegalDocument` carried `$guarded = []`, so a consumer or extension writing
  `LegalConsent::create($request->all())` could forge or backdate a **fresh** proof row — the
  append-only guard only refuses mutation *after* insert, and a forgery is an insert. Nothing is
  mass-assignable now; the package writes through its own curated attribute arrays. If you wrote
  these rows directly, switch to `forceCreate()` / `forceFill()` — the deliberate, auditable door.
- **The consent banner no longer queries the database on every authenticated render.** It runs on
  every page (a Blade `@php` in the layout) and paid three uncached `legal_documents` lookups each
  time. Those lookups are global and publish-driven, so they now come from the publish-invalidated
  cache the package already maintains, with the announce/enforce window filtering done in memory — a
  render with nothing pending costs zero queries. The two per-subject folds are shared as well, so an
  open re-consent *and* deemed window no longer folds the subject's history twice.

### Fixed

- **The registration checklist now describes a form that can actually be submitted.** Two gaps:
  it listed every published document instead of intersecting with the configured registry — so a
  published-but-unregistered document rendered a checkbox that neither the rules validated nor the
  recorder wrote — and it omitted the Art. 8 age attestation entirely while the rules required it,
  which made a form built from the checklist alone impossible to submit. Both are fixed, and each
  item now exposes `field()` (`legal_{key}`, or the key itself for an attestation) so a consumer
  never has to guess the naming convention the rules validate against. `RegistrationChecklistItem`
  gained a nullable `type` (an attestation is about the person, not a document) and a `field` key in
  `toArray()`.
- **The accept-time guard now covers the acceptance sentence, not just the document body.**
  `content_hash` is taken over the text, but a re-consent form shows only `ui_wording` — the one
  sentence a subject reads before ticking — so a release that rewrote just that sentence slipped
  past the guard and was recorded as accepted. Capture and comparison both use
  `DefaultConsentManager::acceptanceFingerprint()` now (body hash folded with the sentence). A bare
  `content_hash` from a pre-0.5.0 caller is still accepted; it simply guards the body alone.
- **The re-consent form's proof inputs are no longer client-writable.** `$hashes` (the accept-time
  TOCTOU guard), `$locale` (which version gets frozen) and `$method` (recorded as HOW the subject
  agreed) were plain public Livewire properties, so a client could rewrite them at submit — defeating
  the guard, freezing another language's version, or planting a provenance that never happened. All
  three are `#[Locked]` now: server-set only.
- **A ticked mandatory checkbox can no longer record nothing.** The registration rules resolved
  documents against the app locale while the recorder fell back to `default_locale`, and the
  registration listener passed no locale at all — so an English app that kept the shipped
  `default_locale => 'de'` and published in `en` only demanded both checkboxes, passed validation,
  and wrote **no ledger row**: a consent the application believes it holds and cannot prove
  (Art. 7(1)). The recorder now resolves against the locale the subject actually saw, then the
  configured `fallback_locale` (which every registration path ignored until now), then the default —
  first hit wins, and a fallback is logged as a warning because the subject agreed to a text in a
  language they may not have been shown (the ledger row records which locale was frozen). A mandatory
  document that resolves in **no** locale of that chain now raises `UnrecordableConsentException`
  instead of being skipped silently. An optional consent still never falls back (Art. 7(4)).
- **`legal-consent:verify-ledger` now catches a forged consent planted as a new chain.** The walk
  groups by `subject_token` and restarts at the public genesis constant on every new one, while the
  consent gate reads a subject by id and never looks at the token — two different keys for one
  question. An attacker who could only `INSERT` exploited exactly that: invent a token nobody used,
  link it to genesis, and the ledger verified as intact while the gate counted the row as a real
  holding. No re-chaining, so keying the hash did not help. The verifier now also enforces the
  binding the token was always meant to have — one subject owns exactly one token, one token belongs
  to exactly one subject — and flags rows written with no token after chaining began, which the walk
  skipped entirely. The check is structural, so it works with or without a key and covers rows that
  predate both. Anonymized rows (erasure nulls the subject id on purpose) are exempt.
- **The verifier no longer claims the hash is unkeyed when it is keyed.** Its closing note was a
  fixed string; it now describes the mode actually in force.
- **One active document version per key is now guaranteed on MySQL and SQLite too.** PostgreSQL
  enforces it with a partial unique index; the other engines relied on `activate()`, which took no
  lock — so two concurrent publishes of the same document could each deactivate the other's
  predecessors and both end up active. Activation is now serialized behind a named lock per
  (tenant, key, locale); a row lock would not do, because a first publish has no rows to lock.
- **The enforceable-document cache no longer 500s under Laravel's default cache hardening.** The gate
  runs on every authenticated request through a cache of the active document set. It cached an Eloquent
  collection, which a serializing store (redis) reads back as an incomplete class when
  `cache.serializable_classes` is `false` — the shipped Laravel default — failing the method's return
  type on every cache hit, i.e. an app-wide 500. The cache now holds plain attribute rows and
  rehydrates them on read, and treats any non-row value as a miss.
- **The WireKit legal-text manager's "release all locales" confirmation now works against the required
  WireKit release.** Its confirm dialog was built with named title/description/confirm slots the
  installed `alert-dialog` (WireKit >= 2.13) does not read, so the trigger opened an empty dialog with
  no way to confirm the release. Rebuilt against the real `alert-dialog` sub-component API.
- **The re-consent affected-subject sweep no longer re-scans the whole document range per page.**
  `AffectedSubjectResolver` keyset-pages the subjects who must re-consent to a new major version. On
  PostgreSQL and SQLite it now issues a sargable ROW-VALUE seek `(subject_type, subject_id) > (…)`
  backed by a new composite index `legal_consents (document_key, locale, subject_type, subject_id)`,
  making the sweep linear — previously every page rebuilt a temporary B-tree and re-scanned the full
  range (quadratic, a term that survived the earlier move off `OFFSET`). On MySQL, whose optimizer
  will not range-scan the index for a row-value comparison, the sweep keeps the OR-form seek, where
  the same index cuts the cost from minutes to seconds at scale (still super-linear there). Run the
  new migration.
- **SQLite no longer nulls an append-only ledger row when its document is deleted.** The
  `legal_consents.document_id` foreign key's `ON DELETE SET NULL` was already dropped on PostgreSQL and
  MySQL (it fought the append-only guarantee) but had been left on SQLite; deleting a superseded document
  on a SQLite consumer with foreign keys enabled silently nulled the immutable row. The foreign key is
  now dropped on SQLite too. Run the new migration.
- **The re-consent form now confirms a recorded acceptance to assistive tech.** Like the settings
  component, it announces the result in an always-present status live region and moves focus to it, so
  a screen-reader user who completes a re-consent gate is no longer left without confirmation and with
  focus dropped to `<body>` (WCAG 4.1.3, 2.4.3).
- **The plain (non-WireKit) admin stubs are now localized.** The legal-text manager and editor stubs
  used hardcoded English while their WireKit variants were fully translated; a non-English deployment
  rendered English chrome with no `lang` marker (WCAG 3.1.2). They now use the shipped `ui.admin_*`
  keys, and the stale-source warning stays in the DOM so a mid-session staleness is announced.
- **A stuck sweep can no longer stay silent for a day.** The two hourly sweeps (`dispatch-notices`,
  `close-objection-windows`) now cap their overlap lock at 2 hours instead of the framework's 24-hour
  default, so a hung run self-clears — and the sweep resumes reporting its heartbeat — well before the
  next legally time-boxed check.
- **Documentation corrections.** The Configuration table now shows that `documents` ships
  pre-populated with an example `terms`/`privacy`/`newsletter` registry (it was documented as `[]`,
  which read as "author the whole registry yourself"); the admin screens now list the Livewire tags
  needed to mount them; a missing `cache.enforceable_ttl` row was added; the tamper-evidence summary
  now states its unkeyed-hash limits honestly (a re-chaining write-capable actor and a tail truncation
  are not caught); and the public `CONTRIBUTING.md` no longer tells contributors to run tooling that
  is not shipped to the public package.

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
  `illuminate/contracts` genuinely provides. Behavior is identical.

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
    hard-gated re-consent behavior.
- **Objection and free-termination recording** — `ConsentManager::object()` and `::terminate()`
  (with `ConsentObjected` / `ConsentTerminated` events and JSON + Livewire surfaces) so a
  subject's objection (§ 308 Nr. 5 / Art. 21) or free termination (§ 675g / § 327r) is provable.
- **Durable-medium delivery proof** — an append-only `legal_notices` table recording that a
  change notice was delivered to a subject, in what form, in which locale, with its exact
  content (CJEU C-375/15). `legal-consent:prune` covers it on the same retention rule as the
  consent ledger, so the proof honors storage limitation (Art. 5(1)(e)) without ever deleting
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
  alias of `--active`. All existing behavior, config, and the `requires_reconsent` column are
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
