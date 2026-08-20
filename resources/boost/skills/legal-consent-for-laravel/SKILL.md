---
name: legal-consent-for-laravel
description: >
  Install, configure, and apply the Legal Consent for Laravel package in a Laravel
  application — versioned legal texts, an append-only acceptance ledger, and a
  re-consent gate.
license: MIT
metadata:
  author: pushery
---

# Legal Consent for Laravel

Use this skill when a Laravel application installs or integrates the
`pushery/legal-consent-for-laravel` package. Laravel Boost surfaces it inside consuming
applications, so keep it focused on adoption — never on package internals.

## Primary Goal

Make the application able to prove, per subject, **which version of which legal text that subject
actually saw and accepted** — and to enforce a fresh acceptance when a material change ships. The
proof artifact is the product: the page and the ledger must render one text, not two that are kept
in sync by hand.

## Workflow

### 1. Install

```bash
composer require pushery/legal-consent-for-laravel
```

The service provider is registered through package discovery.

### 2. Configure

```bash
php artisan vendor:publish --tag=legal-consent
php artisan migrate
```

Every option in `config/legal-consent.php` is documented inline. The ones that usually matter first:

- `documents` — the registry of keys the app uses (`terms`, `privacy`, …) and their legal basis:
  `contract`, `acknowledgement`, `consent`, or `informational`. The last one is for a page you
  must publish but nobody agrees to — an Impressum, a cookie policy, an accessibility statement.
  It uses the same editor and publishing as the rest and never touches registration, the gate or
  notices, so do NOT build a separate renderer for those pages. Its `ui_wording` is `null` — it
  asks the reader for nothing — so guard the wording if your own view renders it.
  Per document, `'ask_at_registration' => false` takes a document off the SIGN-UP form without
  changing anything else — it still gates, so the subject meets it at the re-consent screen. Use
  it for something acknowledged later in-app; use `informational` for a page that binds nobody.
- `document_url` — an invokable class-string with `__invoke(LegalDocument $document): ?string`.
  **Set this.** Without it every consent surface shows the document's title as dead text: the
  registration checkboxes, the re-consent gate and "Your consents". Resolve from
  `$document->locale`, never the app locale — a mandatory document may be published only in the
  default language, and a link built from the page's locale points at nothing. Left null, nothing
  breaks; the titles simply are not links.

  A closure works too, but do not deploy one: `php artisan config:cache` cannot serialize it and
  aborts the whole cache. Nothing before the deploy reproduces that, so use the class-string. The
  same applies to `gate.subject_filter`, and `legal-consent:doctor` reports either one.
- `routes.consent_name` — the route the enforcement middleware sends a blocked subject to.
- `notice_mail` — the change-notice mail. `identity.declarant` names the declaring legal person
  (§ 126b BGB) and is appended to the notice AND to its append-only proof row; leave it null and
  the notice is byte-for-byte what it was. Multi-tenant apps bind `ResolvesNoticeIdentity` instead
  — one global declarant names the wrong legal person in every tenant but one.
- `retention_after_end` + `schedule.prune` — retention is a statement until the sweep is switched on.

### 3. Apply the package

**Author the texts.** Markdown with frontmatter at `resources/legal/{type}/{locale}.md`. The
frontmatter carries the `version` (`MAJOR.MINOR.PATCH`) — always set one; a document without a
version can never gate.

**Publish a version.** Publishing freezes the rendered, sanitized bytes and their hash into an
append-only row:

```bash
# locale is a positional argument, and exactly one notice-mode flag is required:
php artisan legal-consent:publish terms de --active      # re-consent required
php artisan legal-consent:publish terms de --editorial   # silent, no notice
php artisan legal-consent:publish --all --editorial      # the whole matrix, idempotent
php artisan legal-consent:publish --all --only-missing --editorial   # gap-fill only — use THIS in a deploy script
php artisan legal-consent:publish --all --dry-run --editorial        # what would a run do? writes nothing
php artisan legal-consent:check-drift                    # source changed since it was published?
```

**A fresh install has published nothing, and nothing says so.** `legal_documents` is empty after
`migrate`, `Consent::published()` returns `null` for every document, and the read path does not
fall back to the source — so every legal page renders empty with no error and no log. Run
`legal-consent:publish --all --editorial` once, or ask `legal-consent:doctor`, which names every
registered document with no published version.

**In the deploy script, use `--all --only-missing --editorial`, not the bare `--all`.** Re-running
`--all` changes nothing only while the sources are unchanged. Once a text is edited and its version
bumped, the next deploy publishes it as `editorial` — the one notice mode that tells nobody, chosen
by a script instead of a person. `--only-missing` never reads a combination that already has an
active version, so it cannot classify a change. It also treats a source with no text yet as a named
skip rather than a failure, which is what a draft-backed document looks like before an editor has
written it.

## Testing your own app against it

`Consent::fake()` swaps the manager for an in-memory double — no database, no migrations of this
package's tables into your test schema.

```php
$fake = Consent::fake();

$this->post('/register', [...]);

$fake->assertAccepted($user, 'terms');
```

Reads default to a fully-consented subject (nothing outstanding, `hasCurrent()` true, nothing
published), so a test about something else is never blocked by a gate it did not mention. Declare
what you care about with `owes($user, 'terms')`, `publishes($document)`, `checklistIs(...)`,
`statusIs([...])` or `historyIs([...])`.

Writes are recorded, not performed: the returned `LegalConsent` carries the attributes but
`exists` stays false. Assertions: `assertRecorded`, `assertNotRecorded`, `assertAccepted`,
`assertWithdrawn`, `assertNothingRecorded`, `assertRecordedCount`, plus `recorded()` for anything
else. `assertAccepted` matches `granted`, `acknowledged` and `re_accepted` — never
`deemed_accepted`, because silence is not an act of the subject.

The mode is the legal classification of the change, so it is never guessed: `--active` (the subject
must accept again), `--deemed` (silence counts, contract terms only), `--info` (announced, takes
effect regardless), `--editorial` (no material change).

**Enforce re-consent.** Add the middleware to the routes that require an accepted contract:

```php
Route::middleware(['auth', 'legal.consent'])->group(function (): void {
    // ...
});
```

A browser request is redirected to the consent route; a JSON request gets `409
legal_consent_required` with the outstanding document keys.

**Render the frozen text, never the source.** The public `/terms` page must read the published row,
so `hash(page) === ledger.content_hash` is a tautology rather than a chore:

```php
$document = Consent::published('terms', app()->getLocale()); // null when unpublished, never throws
```

**Collect acceptance at registration** from what is actually published, instead of a hardcoded list:

```php
foreach (Consent::registrationChecklist(app()->getLocale()) as $item) {
    // $item->key, $item->title, $item->wording, $item->required
}
```

**Record it** through the manager or facade, passing the hash the subject was shown so a version
released mid-session cannot be frozen against them:

```php
Consent::accept($user, 'terms', ConsentContext::forMethod(ConsentMethod::RegistrationCheckbox), $locale, $shownHash);
```

**No registration form (OAuth, SSO, invitations)?** Capture the first acceptance in an interstitial
shown after authentication and before first use, and use `ConsentMethod::FirstUseGate` for it:

```php
<livewire:legal-consent.re-consent-form :method="ConsentMethod::FirstUseGate" />
```

**Drop in the optional UI** (needs `livewire/livewire`; publish `legal-consent-wirekit` for the
WireKit variants, which need `pushery/wirekit` ≥ 2.26.0 — below 2.17.1 the admin editor loses
everything typed into it, and below 2.26.0 WireKit's own screen-reader strings are announced in
English on a German consent surface):

```blade
<livewire:legal-consent.reconsent-form />
<livewire:legal-consent.consent-settings />
```

Both screens expose `object` and `terminate` besides their own action. **Every public method of an
embedded Livewire component is reachable whether or not the template renders a button for it**, so
if your product has no answer to those two, switch them off at the embed rather than deleting
buttons:

```blade
<livewire:legal-consent.consent-settings :allow-objection="false" :allow-termination="false" />
<livewire:legal-consent.reconsent-form :allow-objection="false" :allow-termination="false" />
```

A transition that does not apply answers `404`, not `500` — a key nothing published, a key
unpublished between the render and the click, or an objection against a consent (which is withdrawn,
not objected to). The reason rides on the exception and reaches neither the page nor your log
(Laravel never reports a `NotFoundHttpException`), so report `404`s yourself if you want to see
them.

If you call the manager or the facade directly instead of through the component, catch these
yourself — all in `Pushery\LegalConsent\Exceptions`: `LegalDocumentNotFound`,
`NotWithdrawableException`, `NotObjectableException`, `NotTerminableException`,
`NotConsentBearingException`. They are refusals to record a row the ledger cannot take back, not
failures to work around.

The last one catches the case that is easiest to reach by accident: an `informational` document —
an Impressum, a cookie policy — is published so it can be read and asks the reader for nothing, so
`Consent::accept()` and `Consent::record()` refuse it. The JSON API answers `422` with an `error`
of `not_consent_bearing`. Do not offer such a document as something to accept; render it, and let
the gate ignore it.

## Examples

A minimal, complete adoption: publish the config, author `resources/legal/terms/de.md` with
`version: 1.0.0`, run `legal-consent:publish terms de --active`, put `legal.consent` on the
authenticated route group, render `Consent::published('terms', 'de')?->html` on the public page, and
embed `<livewire:legal-consent.reconsent-form />` on the consent route. Schedule
`legal-consent:dispatch-notices` so a change with a grace period actually reaches subjects, and put
`legal-consent:publish --all --editorial` in the deploy script so a fresh database is never left
with empty legal pages.

## Anti-Patterns

- **Do not render the source text on the public page.** Render the published row. Rendering the
  source lets the page and the ledger drift with no test turning red — the proof breaks silently.
- **Do not add a second sanitizer or hasher.** The package sanitizes and hashes in one place; a
  second one means the ledger hashes one form while the page renders another.
- **Do not hardcode registration checkboxes.** Derive them from `registrationChecklist()`, or the
  form will block on a document that does not exist, or quietly omit one that does.
- **Do not edit `legal_documents` rows.** They are append-only and the database refuses it; publish a
  new version instead.
- **Do not publish `legal-consent-users-cache` or `legal-consent-backfill` casually.** One drops a
  column from your `users` table, the other backfills history — both are deliberate, separate steps.
- **Do not allowlist Livewire's endpoint path in `middleware.allowlist_paths`.** The gate resolves
  it from the installation already. Livewire 4 derives the prefix from `APP_KEY`, so writing your
  own `/livewire-<hash>/*` is green in development and silently wrong in production.
- **Do not leave `document_url` unset if your app has legal pages.** Every consent surface then
  shows a title the subject cannot open — including the re-consent gate, where they cannot continue
  until they agree.
- **Do not ship a closure in `document_url` or `gate.subject_filter`.** Both accept one, and both
  break `php artisan config:cache` — a failure that first appears in the deploy, because nothing
  local caches. Use an invokable class-string; `legal-consent:doctor` names the offenders.
- Do not document package internals here; keep integration guidance in the application.
