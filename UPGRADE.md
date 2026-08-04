# Upgrade Guide

This guide documents the changes you need to make when upgrading between
breaking versions of `pushery/legal-consent-for-laravel`. Because the package is
still `0.x`, a **minor** bump may contain breaking changes (SemVer `0.y.z`).

## 0.10.0 → 0.11.0

**Nothing to do unless you publish the WireKit view variants.** No migrations ship, no config
key changes, and the PHP API is untouched. If you use the plain stubs, your own markup, or no
UI at all, you can skip this section entirely.

### Act on this: the WireKit views now need `pushery/wirekit` ≥ 2.26.0

If you ran `vendor:publish --tag=legal-consent-wirekit`, upgrade WireKit before taking this
release:

```bash
composer require "pushery/wirekit:^2.26.0"
```

**What the floor buys, and why it is not cosmetic.** A handful of strings inside those views are
WireKit's own, not this package's — the external-link hint on the full-text link, the sr-only
prefix on the admin policy notice, the dismiss label. They run through `__()` with the English
text as the key, and until 2.26.0 WireKit shipped no translation catalog. So on a German consent
screen a screen reader announced `(opens in new tab)` and `Notice:` while every visible word
around them was German.

There is no visible symptom. Nothing on the page looks wrong, no exception is thrown, and the
only person who encounters it is the one who cannot see the screen — at the moment they are
deciding something legally binding. 2.26.0 ships `de` and registers it itself, so the correct
announcement arrives with no publishing step and no configuration on your side.

Below 2.17.1 the two older breakages from `0.9.0` still apply unchanged: the admin editor's
`wire:model` lands on the wrapper instead of the textarea and **everything typed into it is lost
on save**, and the manager table cannot emit a row header (WCAG 1.3.1).

### If your app is not German or English, supply five strings yourself

WireKit's catalog covers `en` and `de`. This package bundles seven locales, so in `es`, `fr`,
`it`, `nl` and `pt` those WireKit strings are **still announced in English**. Put them in your
app's `lang/{locale}.json`, which is the file Laravel's JSON loader reads:

```json
{
    "(opens in new tab)": "(se abre en una pestaña nueva)",
    "Notice": "Aviso",
    "Dismiss": "Descartar"
}
```

Do not translate the copy that `--tag=wirekit-lang` writes into `lang/vendor/wirekit/` — the
loader does not read that directory, so edits there have no effect.

**This package deliberately does not ship those for you.** They are JSON string keys, which are
application-global: defining them here would silently retranslate every other WireKit component
in your app, including ones this package never touches. That is not a choice a dependency should
make on your behalf.

### If you already copied the WireKit stubs into your app

`vendor:publish` writes the views into your project, so your copies do not change when you
upgrade the package. Nothing in this release changes those files — the improvement lives in
WireKit itself, so upgrading WireKit is enough and re-publishing is not required.

## 0.9.0 → 0.10.0

**On PostgreSQL, MySQL or SQLite there is nothing to do beyond running the migration**, and
everything else below is invisible to an application that installs this package into a normal
Laravel project.

⚠️ **On MariaDB, SQL Server or any other engine the migration now stops instead of running.** If
that is you, read *The migration now REFUSES an engine it cannot protect* below **before** you run
`php artisan migrate`.

### Run the migration

```bash
php artisan migrate
```

It makes `legal_documents.ui_wording` nullable, so an `informational` document — a page that is
published and binds nobody — can carry no acceptance sentence. It changes no existing row.

⚠️ **On SQLite this migration rebuilds the table**, because SQLite cannot alter a column in
place. That is handled: the migration re-installs the proof-column trigger afterwards, on both
`up()` and `down()`. If you have written your OWN triggers on `legal_documents`, re-create them
after upgrading — a rebuild keeps indexes and drops triggers, and nothing warns you.

### ⚠️ The migration now REFUSES an engine it cannot protect — MariaDB, SQL Server, anything else

This is the only change in this release that can stop `php artisan migrate`, and it affects
installations that upgraded cleanly until now. Up to 0.9.0 a fourth engine migrated green and
simply got **no proof trigger** on `legal_documents` — the immutability this package builds its
evidentiary weight on, silently absent. It now stops instead:

```
legal_documents cannot be protected on the 'mariadb' driver: the proof-column trigger is
written for PostgreSQL, MySQL and SQLite. Publishing legal texts without it would leave every
frozen row editable, so this stops rather than continuing quietly.
```

**MariaDB is affected even though the trigger syntax would run there.** Laravel returns the
driver name from your connection configuration verbatim, and it ships `mariadb` as its own
driver — so a MariaDB connection is never seen as `mysql`. SQL Server (`sqlsrv`) and any custom
driver hit the same refusal.

The refusal is raised **before** anything is altered, so a refused `migrate` leaves your schema
exactly as it was; there is no half-applied state to clean up. Your options:

- move `legal_documents` to PostgreSQL, MySQL or SQLite — the three engines whose trigger this
  package writes and tests against real servers;
- or stay on `0.9.x` until MariaDB support ships, and know that your published rows are **not**
  protected by a database-level trigger today. The application-layer guard still refuses an
  edit through the model, but a direct `UPDATE` is not stopped.

### An `informational` document published under 0.8.0 or 0.9.0 keeps its wrong sentence

`informational` arrived in 0.8.0. Until this release, publishing one froze the default acceptance
sentence into `ui_wording` — a page that binds nobody, carrying "Ich habe die Bedingungen gelesen
und akzeptiere sie." The fix applies to new publishes only, and the migration deliberately
backfills nothing: `ui_wording` is a proof column, immutable after publish by both the model and
the database trigger, so nothing may rewrite it in place. Find affected rows:

```sql
select * from legal_documents where type = 'informational' and ui_wording is not null;
```

(`select *` rather than a column list on purpose: `key` is a reserved word on MySQL and needs
backticks there, while PostgreSQL wants double quotes — and the row you are looking at is worth
seeing whole anyway.)

The remedy is to **publish a new version** of each such document under 0.10.0, which stores no
sentence at all. The old version stays in the ledger, which is the point — the history is
evidence, and rewriting it is what this package exists to prevent.

### `ui_wording` can now be `null`, and code that renders it needs to say so

Only an `informational` document has none. Every other class still fails loudly when no sentence
resolves, so nothing that was working stops working — but the types changed:

```diff
-Document::$uiWording        // string
-PublishedDocument::$uiWording  // string
+Document::$uiWording        // ?string
+PublishedDocument::$uiWording  // ?string
```

If your own view renders the wording of an arbitrary document, guard it:

```blade
@if ($document->uiWording !== null)
    <p>{{ $document->uiWording }}</p>
@endif
```

`DefaultConsentManager::acceptanceFingerprint()` now throws for an informational document rather
than hashing an empty sentence. Nothing accepts such a page, so nothing needs to fingerprint one.

### An objection and a termination now refuse the classes they cannot apply to

`Consent::object()` reaches a contract and a privacy notice; `Consent::terminate()` reaches only
a contract. The other combinations throw `NotObjectableException` / `NotTerminableException`
instead of appending a ledger row that asserts a state which does not legally exist.

If your app calls either on an arbitrary document key, check the type first — or let the
exception surface, which is the honest outcome: the ledger is append-only, so a wrong row cannot
be taken back.

The bundled settings component can also switch the two endpoints off entirely, which is worth
doing if your product has no answer to them. **Removing the buttons from a published view does
not**: Livewire dispatches to any public method of an embedded component.

```blade
<livewire:legal-consent.consent-settings :allow-objection="false" :allow-termination="false" />
```

### An unknown `document_key` is now a `404`, not a `500`

Three status codes changed, all in the same direction — from "this package broke" to "your
request does not name something that exists here":

| Request | before | now |
|---|---|---|
| JSON API, a key that is not published | `500` | `404` + `error: unknown_document` |
| Livewire component, a key that is not published | `500` | `404` |
| Livewire component, a transition the class cannot carry | `500` | `404` |

**Act on this only if you assert on the old codes.** A client that treats `5xx` as retryable was
retrying a typo against endpoints that append to a ledger; a monitoring rule that alerted on this
package's `500`s was alerting on its consumers' mistakes. Both stop by themselves — nothing to
change unless you have a test or an alert pinned to `500`.

The JSON API error body is unchanged in shape and now covers this case too, so a client can tell
the two refusals apart:

```json
{ "error": "unknown_document", "message": "No legal document is published under the key 'privacy'.", "document_key": "privacy" }
```

`404` also covers "not published **here**": a document whose only active version is in a locale you
do not serve, and which `fallback_locale` does not reach, is not being served to that subject.

The Livewire components stay deliberately quieter: the rendered `404` is the framework's plain page
and carries nothing of the reason, which names the document key and its legal class — the template
author's business and not the subject's.

**Be aware of where that reason does NOT go.** It rides on the exception, and Laravel never reports
a `NotFoundHttpException`, so it reaches neither your log nor your error page. If you want these
visible, report `404`s yourself — a custom exception handler, or an APM configured to capture them.

### `GET /legal/status` answers `{}` instead of `[]` when nothing is published

The payload is a map keyed by document key. PHP cannot tell an empty map from an empty list, so the
empty case used to serialize as a JSON **array** — which breaks a client that decodes it into a
dictionary, on exactly the response that carries no other sign that anything is wrong. It is now
always an object.

**Act on this if** your client branches on the array form (`Array.isArray(res)`, a decoder with an
array fallback). The populated shape is unchanged, so a client that only reads keys needs nothing.

### The composer manifest now names six more `illuminate/*` components

`illuminate/auth`, `illuminate/bus`, `illuminate/cache`, `illuminate/collections`,
`illuminate/http` and `illuminate/routing` were being imported by shipped code without being
declared. Every one of them is already present in any Laravel application, so `composer update`
resolves them from what you have — nothing new is downloaded and no version is forced.

It matters only if you deliberately installed this package **without** `laravel/framework`,
against a hand-picked set of components. That never worked: the service provider imported
classes the manifest did not require, so the container failed on boot. It resolves correctly now.

### `LegalDraftSaved::dispatch()` and `LegalDraftReviewed::dispatch()` are gone

Both event classes dropped the framework's `Dispatchable` trait. If you dispatch either event
yourself — unusual, since this package dispatches them — replace the static call:

```diff
-LegalDraftSaved::dispatch($draft, $actor);
+event(new LegalDraftSaved($draft, $actor));
```

**Listening is unaffected.** The class names, the constructor signature and the public
properties are unchanged, so every listener, subscriber and `Event::fake()` assertion keeps
working untouched.

### If you published the optional v1 backfill migration

Re-publish it to pick up the corrected version:

```bash
php artisan vendor:publish --tag=legal-consent-backfill --force
```

It previously coerced two configuration values and a database id without checking them. On an
application whose auth configuration holds something other than a class-name string, that wrote
the literal `Array` into `subject_type`; and a non-numeric user id was folded onto `0`, attaching
an imported acceptance to whichever user has that id. Rows it cannot read are now skipped instead.
If you already ran the old version, check `legal_consents` for rows with `source = 'v1_backfill'`
and a `subject_type` of `Array` or a `subject_id` of `0` before trusting the import.

## 0.8.0 → 0.9.0

`0.9.0` carries **one** thing you must act on, and only if your app publishes the WireKit view
variants. **No new migrations ship**, no config key changes, and the PHP API is untouched.

### Act on this: the WireKit views now need `pushery/wirekit` ≥ 2.17.1

If you ran `vendor:publish --tag=legal-consent-wirekit`, upgrade WireKit before taking this
release:

```bash
composer require "pushery/wirekit:^2.17.1"
```

The views were relying on component capabilities that arrived in 2.17.1. Below it, two things
fail **without any error**:

- the admin editor's `wire:model` lands on the component's wrapper instead of its textarea, so
  **everything typed into the editor is lost when you save**;
- the manager table cannot emit a row header, so every status cell loses its programmatic
  association to the document it belongs to (WCAG 1.3.1).

If you do **not** use the WireKit views — the plain stubs, your own markup, or no UI at all —
nothing here applies. WireKit stays a dev-only dependency of this package; it is never installed
into your app on its behalf.

### If you already copied the WireKit stubs into your app

`vendor:publish` writes the views into your project, so your copies do not change when you upgrade
the package. Re-publish them to pick up the new editor binding, the declared toolbar, and the row
header:

```bash
php artisan vendor:publish --tag=legal-consent-wirekit --force
```

`--force` overwrites. Diff first if you have edited the published copies — the package ships them
as a starting point and expects you to.

### Nothing else changes

The admin editor's binding and toolbar, and the manager's row header, are internal to those views.
No route, class, event, config key or database column moves in this release.

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
