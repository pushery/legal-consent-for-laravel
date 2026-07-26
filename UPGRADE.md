# Upgrade Guide

This guide documents the changes you need to make when upgrading between
breaking versions of `pushery/legal-consent-for-laravel`. Because the package is
still `0.x`, a **minor** bump may contain breaking changes (SemVer `0.y.z`).

## 0.7.0 → 0.8.0

`0.8.0` adds a fourth document class and carries **one** breaking change, which only affects code
that constructs a package class by hand. **No new migrations ship** — nothing in your schema
changes, and the new class needs no column of its own.

### Breaking: `LegalDocumentReleaser` lost an unused constructor argument

It took a `TenantContext` it never used. If you resolve the class from the container — which is
how the package itself and the documentation use it — nothing changes:

```php
app(LegalDocumentReleaser::class)->release(/* … */);
```

Only a hand-rolled construction needs the third argument dropped:

```php
// before
new LegalDocumentReleaser($publisher, $resolver, $tenants);
// after
new LegalDocumentReleaser($publisher, $resolver);
```

### New: the `informational` legal basis

For a page you must publish but which binds nobody — an Impressum (§ 5 DDG), a cookie policy, an
accessibility statement. Nothing changes for existing documents; this is purely additive.

```php
// config/legal-consent.php
'impressum' => [
    'source' => 'markdown',
    'legal_basis' => 'informational',
],
```

```bash
php artisan legal-consent:publish impressum de --editorial
```

Such a page never appears in the registration checklist, never writes a ledger row, never gates
access and never sends a notice — and it is the only class that falls back to your
`default_locale` when a translation is missing.

**One guard is worth knowing about before you edit the registry:** a document whose active version
is a contract, privacy notice or consent can no longer be republished as `informational`. That
would silently remove the gate while the recorded acceptances stayed on file, so it is refused.
Register the page under a new key instead.

If you have a published `config/legal-consent.php`, run the new doctor after upgrading — a flat
merge means keys added inside a block your file already declares never reach your runtime:

```bash
php artisan legal-consent:doctor
```

## 0.5.0 → 0.6.0

`0.6.0` is deep-audit hardening. It is a **minor** bump but carries one breaking change to the
tamper-evidence chain format, plus additive, opt-in features. **No new migrations ship** — nothing in
your schema changes.

### 1. Tamper-evidence chains reset (only if you enabled `tamper_evidence`)

The canonical serialization that feeds each ledger row's `prev_record_hash` is now **injective**:
`null` is distinguished from `''`, and every field is length-prefixed so an embedded separator can no
longer shift field boundaries — closing a hole where two distinct rows could share a hash. This
**changes every computed chain hash**, so a chain written by any earlier version no longer verifies
(the previous `\x1f` format was byte-identical across **v0.1.0–v0.5.0**, so every prior release is
affected, not only v0.5.0).

- If `legal-consent.tamper_evidence` was **off** (the default), there is nothing to do.
- If it was **on** with persisted rows, `legal-consent:verify-ledger` reports a break after upgrading.
  Treat it as a one-time **chain reset**. The rows themselves are untouched and remain valid proof —
  each row's `content_hash`, `ui_wording_snapshot` and `subject_token` are unaffected; only the chain
  *linkage* no longer verifies. From `0.6.0` on, newly appended rows chain under the injective format
  and verify normally; if your compliance process needs a clean verify, archive/export the pre-upgrade
  ledger as the baseline and start the verified chain from the first `0.6.0` append.
- **Rolling deploy:** during a mixed-version window, old (`\x1f`) and new (`S<len>:`) writers append
  interleaved link formats and the verifier surfaces transient breaks. Quiesce the ledger writers (or
  run appends/sweeps from a single version) across the cutover, and run `verify-ledger` only once the
  whole fleet is on `0.6.0`.

### 2. Opt into the registration accept-time guard (optional)

The registration path can now catch a version published between page load and submit — the guard the
re-consent form already has. It is **opt-in** and off by default:

- Each `Consent::registrationChecklist()` item exposes `contentHash` (the render-time fingerprint) and
  `hashField()` (`legal_{key}_hash`). Render that hidden input — the shipped `consent-checkboxes` stub
  does it automatically when you feed it a checklist item's `->toArray()` — and a mid-form version
  change throws `DocumentChangedException` instead of silently freezing a text the registrant never
  saw.
- A form that does **not** render the field behaves exactly as before; no action is required to keep
  the current behavior.

## 0.3.x → 0.4.0

`0.4.0` turns the legal texts from a read-only source into an admin-maintained,
provable record: drafts are edited and reviewed, published versions are frozen,
and the public page and the consent ledger read the same frozen bytes. Most of
the surface below is new; the breaking parts are the removed config keys, the
removed source drivers, and the fact that a published row can no longer be
edited in place.

### 1. Run the new migrations

Four new migrations ship, plus one optional cleanup. Publish and migrate:

```bash
php artisan vendor:publish --tag=legal-consent-migrations
php artisan migrate
```

| Migration | What it does |
| --- | --- |
| `…_000009_add_document_id_index_to_legal_consents_table` | Indexes the ledger's document lookups. |
| `…_000010_create_legal_drafts_table` | The draft store the editor writes to. |
| `…_000011_protect_legal_documents_proof_columns` | Makes a published `legal_documents` row immutable (see §4). |
| `…_000012_add_chain_fork_guard_to_legal_consents` | Unique index `(subject_token, prev_record_hash)` so a concurrent tamper-chain fork is rejected, not written (only relevant with `tamper_evidence` on). |
| `optional/…_000003_drop_legal_consent_cache_from_users_table` | Drops the unused per-user cache columns. Optional — publish it explicitly if you added them. |

`000011` is deliberately the last migration in the set, so it runs after every
backfill of an existing proof column. If you maintain a fork with extra
migrations that rewrite `legal_documents`, read §6 before you add them.

### 2. Configuration changes

Re-publish the config (or merge these by hand):

```bash
php artisan vendor:publish --tag=legal-consent-config --force
```

**Removed**

- **`sources.database`** — the database source driver was a no-op and has been
  deleted. Documents maintained inside the app now live in the **draft store**;
  set a document's `source` to `drafts` (see below).
- **`sources.cms`** (and its `resolver` closure) — the CMS-adapter path is
  removed. The built-in draft store and admin editor replace it. If you resolved
  legal text from an external CMS, move that content into a draft (author it in
  the editor, or seed `legal_drafts` from your CMS) so the published bytes are
  the bytes the ledger proves.
- **`tenancy.column`** — the tenant column name is now the fixed constant
  `tenant_id` (`Pushery\LegalConsent\Support\TenantContext::COLUMN`). If you set
  `tenancy.column` to a custom name, rename that column to `tenant_id`.

**Added**

- **`sources.drafts`** — the driver behind the admin-maintained documents. A
  document opts in with `'source' => 'drafts'` in its `documents.*` entry.
- **`admin.ability`** (default `null`) — the Gate ability that guards the admin
  screens. It is **required** to reach them: with it unset, both screens return
  `404`. There is no opt-out — name an ability and define the Gate. See §5.
- **`cache.enforceable_ttl`** — how long the set of currently-enforceable
  documents is cached (seconds). The cache is flushed automatically on publish.

### 3. Removed and renamed classes

If you referenced any of these directly, update your code:

| Removed / renamed | Replacement |
| --- | --- |
| `Content\Drivers\DatabaseDriver` | `Content\Drivers\DraftDocumentSource` (`sources.drafts`) |
| `Content\CmsResolver`, `Content\ClosureCmsResolver`, `Content\Drivers\CmsAdapterDriver` | The draft store + admin editor |
| `Content\LegalDocumentManager` | `Content\LegalSourceRenderer` |
| `LegalDocumentManager::document(...)` | `LegalSourceRenderer::renderSource(...)` |

### 4. Published documents are now immutable

A published `legal_documents` row is frozen proof — the exact sanitized text a
subject was shown and the hash the ledger snapshots. From `0.4.0` a database
trigger (on PostgreSQL, MySQL and SQLite) plus a model hook reject any edit to a
proof column after publish; only activation state and the sweep timestamps may
change. Correcting a legal text is a **new version**, never an in-place edit.

If your app (or a seeder) updated a published row directly, that write now
raises `LegalDocumentFrozenException` (through the model) or a database error
(through raw SQL). Publish a new version instead.

### 5. The public page reads the frozen row

Render a public legal page from the frozen, published row via the new read path:

```php
$document = Consent::published('terms', app()->getLocale());

// $document?->html is the exact stored bytes; $document?->contentHash the exact
// stored hash — the same text the ledger proves. Returns null before a locale is
// published (render an "in preparation" shell), and never falls back to another
// locale.
```

Do not render the *source* on a public page — a source can drift from the
published row between an author's edit and the next publish, and a subject would
then accept text A while the ledger snapshots `hash(text B)`.

### 6. The admin screens (optional)

Two Livewire screens ship as publishable stubs — a manager (release per
document) and an editor (per document, per locale). They are **opt-in and
fail-closed**:

1. Name a Gate ability in `admin.ability` and define it, e.g.

   ```php
   // config/legal-consent.php
   'admin' => ['ability' => 'manage-legal-texts'],

   // a service provider
   Gate::define('manage-legal-texts', fn ($user) => $user->isLegalAdmin());
   ```

2. Route the components behind your own admin middleware, mounting them as Livewire
   components. With `admin.ability` unset, or the Gate denied, both screens `404`
   (they never reveal they exist).

   ```blade
   <livewire:legal-consent.legal-text-manager />
   <livewire:legal-consent.legal-text-editor :document-key="'terms'" :locale="'de'" />
   ```

   The editor mounts with a document `key` and a `locale`; the manager takes none.

Plain stubs are published with `--tag=legal-consent-views`; the WireKit variants
with `--tag=legal-consent-wirekit`. AI translation is an optional seam: bind
`Pushery\LegalConsent\Contracts\LegalTextTranslator` to enable the editor's
"Translate" action; unbound, it reports that no translator is configured.

### 7. Verify existing data after upgrading

Run the verifier once after migrating:

```bash
php artisan legal-consent:verify-documents
```

It re-derives every published row's hash and flags any row whose stored hash,
notice mode, or wording locale disagrees with its content — so tampering or a
pre-`0.4.0` mislabeled `ui_wording` snapshot is visible rather than silent.

### 8. Extending the `legal_documents` table (maintainers)

The immutability trigger from `000011` fails **closed** on PostgreSQL — a proof
column added by a future migration is protected automatically. On MySQL and
SQLite the trigger enumerates the columns present when it was created, so a proof
column added later would slip past it. If you add a migration that backfills or
rewrites a `legal_documents` column, it must **drop the trigger, apply the data
change, and re-create the trigger** — the guard aborts a migration-time `UPDATE`
exactly as it aborts runtime tampering. The `DocumentImmutabilityTest` iterates
the live column list against the allowlist and fails if a new column is left
unprotected.
