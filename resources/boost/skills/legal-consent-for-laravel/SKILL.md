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
  notices, so do NOT build a separate renderer for those pages.
- `routes.consent_name` — the route the enforcement middleware sends a blocked subject to.
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
php artisan legal-consent:check-drift                    # source changed since it was published?
```

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

**Drop in the optional UI** (needs `livewire/livewire`; publish `legal-consent-wirekit` for the
WireKit variants):

```blade
<livewire:legal-consent.reconsent-form />
<livewire:legal-consent.consent-settings />
```

## Examples

A minimal, complete adoption: publish the config, author `resources/legal/terms/de.md` with
`version: 1.0.0`, run `legal-consent:publish terms de --active`, put `legal.consent` on the
authenticated route group, render `Consent::published('terms', 'de')?->html` on the public page, and
embed `<livewire:legal-consent.reconsent-form />` on the consent route. Schedule
`legal-consent:dispatch-notices` so a change with a grace period actually reaches subjects.

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
- Do not document package internals here; keep integration guidance in the application.
