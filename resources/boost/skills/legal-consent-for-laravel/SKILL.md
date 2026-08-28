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

Your user model can be keyed however you key it — the ledger stores `subject_id` as a
64-character string, so an auto-increment id, a UUID and a ULID all fit and `HasUuids` needs no
adapter. Do NOT add a cast or a config option for it; there is nothing to configure.

### 2. Configure

```bash
php artisan vendor:publish --tag=legal-consent-config
php artisan vendor:publish --tag=legal-consent-lang
php artisan migrate
```

There is an umbrella tag, `--tag=legal-consent`, which adds migrations and views to those two.
**Do NOT reach for it on an application that has WireKit installed.** It copies the whole view
tree into `resources/views/vendor/legal-consent/`, whose top level is the PLAIN stubs, and a
published view is resolved before either of the package's own sets — so the umbrella publish
silently turns `ui.variant => 'auto'` into plain, unstyled consent screens. Nothing errors and
`legal-consent:doctor` cannot see it. If views are already published on such an app, run
`php artisan vendor:publish --tag=legal-consent-wirekit --force` to pull the WireKit twins over
the published copies (`--force` is required; the files exist).

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
- `routes.web` — off by default. Switch it on if you render the plain settings stub without
  Livewire: it registers `POST {web_prefix}/consent/withdraw` behind `['web', 'auth']`, and the
  presenter then fills each held consent's `withdraw_url`. With it off the stub renders no
  withdraw form at all, which is deliberate — a form with no action is the appearance of a
  control, not a control. Not needed with the Livewire component, which calls its own action.
- `ui.variant` — `auto` by default: the WireKit-native views are served when `pushery/wirekit`
  ≥ 2.26.0 is installed, the plain ones otherwise. Pin `plain` or `wirekit` to decide it yourself.
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

The mode is the legal classification of the change, so it is never guessed: `--active` (the subject
must accept again), `--deemed` (silence counts, contract terms only), `--info` (announced, takes
effect regardless), `--editorial` (no material change).

**A fresh install has published nothing, and nothing says so.** `legal_documents` is empty after
`migrate`, `Consent::published()` returns `null` for every document, and the read path does not
fall back to the source — so every legal page renders empty with no error and no log. Run
`legal-consent:publish --all --editorial` once, or ask `legal-consent:doctor`, which names every
registered document with no published version.

**When the application deletes an account, call `Consent::forget($user)`.** It strips
`subject_type`, `subject_id`, `ip_address`, `user_agent` and `request_id` from both ledgers and
keeps everything that proves the consent, including the `subject_token` pseudonym that still ties
the two together (Art. 17(3)(b)/(e)). It returns counts per ledger.

Do NOT write this by hand and do NOT try to clear those columns with an update — both ledgers
refuse every `UPDATE`, at the model and at a database trigger. A hand-rolled delete-and-reinsert
also breaks the tamper chain: the erased columns are inputs to the row hash, and a row's hash folds
in its own link, so the rewrite has to re-link everything after it. `Consent::forget()` does that;
a consumer version almost certainly does not.

In tests, `Consent::fake()` records the call — assert it with `assertForgotten($user)` and
`assertNotForgotten($other)` rather than migrating these tables into your test database.

**A missing markdown file still fails `--only-missing`.** Only a source waiting on an author is
warned about and skipped — the bundled draft source, or your own if it implements
`Pushery\LegalConsent\Content\AwaitsAuthoring`. Everything else counts as provisioned, so a
deployment missing a legal text goes red instead of leaving an empty page behind a green deploy.
Do NOT add a flag for this; it follows from the source.

**In the deploy script, use `--all --only-missing --editorial`, not the bare `--all`.** Re-running
`--all` changes nothing only while the sources are unchanged. Once a text is edited and its version
bumped, the next deploy publishes it as `editorial` — the one notice mode that tells nobody, chosen
by a script instead of a person. `--only-missing` never reads a combination that already has an
active version, so it cannot classify a change. It also treats a source with no text yet as a named
skip rather than a failure, which is what a draft-backed document looks like before an editor has
written it.

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
    // $item->field() is the input NAME — never build it yourself. A document control is
    // `legal_{key}`, but the Art. 8 age attestation is named by its key alone, so a
    // hand-built `legal_{$item->key}` renders a required box that can never be satisfied.
    // $item->title, $item->wording, $item->required, $item->url
}
```

**Double opt-in?** Two rows, and only the second is a consent:

```php
Consent::requestConfirmation($user, 'newsletter', $context);  // action: optin_requested — NOT held
Consent::confirm($user, 'newsletter', $context);              // action: confirmed — now held
```

The unconfirmed row does not raise `accepted_major` and `hasCurrent()` stays false;
`statusFor()[$key]['pending_confirmation']` is how a screen shows that middle state instead of
offering the control again. The sibling flag `retired` says the document has no active version any
more: the holding stays, its withdrawal control stays, and it is never `outstanding` — retiring a
document must not close the door on Art. 7(3). Listen for `ConsentConfirmationRequested` to send the mail — the
package owns the ledger, not the mailbox — and never for `ConsentRecorded`, which does not fire
for a request.

**Record it** through the manager or facade, passing the hash the subject was shown so a version
released mid-session cannot be frozen against them:

```php
Consent::accept($user, 'terms', ConsentContext::forMethod(ConsentMethod::RegistrationCheckbox), $locale, $shownHash);
```

**No registration form (OAuth, SSO, invitations)?** With the `Registered` listener on, the callback
writes an acceptance row for every mandatory document without a human having done anything. The
recorder logs that; set `registration.without_form_fields => 'refuse'` and it raises
`UnevidencedConsentException` and records nothing instead. Better still, capture the first
acceptance in an interstitial shown after authentication and before first use, and use
`ConsentMethod::FirstUseGate` for it:

```blade
<livewire:legal-consent.reconsent-form :method="\Pushery\LegalConsent\Enums\ConsentMethod::FirstUseGate" />
```

**Drop in the optional UI** (needs `livewire/livewire`. The WireKit-native views are served
automatically when `pushery/wirekit` ≥ 2.26.0 is installed — `legal-consent.ui.variant` defaults to
`auto`; publish `legal-consent-wirekit` only to customize them. Below 2.17.1 the admin editor loses
everything typed into it, and below 2.26.0 WireKit's own screen-reader strings are announced in
English on a German consent surface, which is why the floor is part of the automatic choice):

```blade
<livewire:legal-consent.reconsent-form />
<livewire:legal-consent.consent-settings />
```

Each settings row carries `outstanding` — true when the subject holds an **older major** than the
one that is live, which is the state the gate will otherwise compel. It is computed exactly as
`Consent::statusFor()` computes it, and a voluntary consent is never outstanding (Art. 7(4)). The
shipped views render it as **Action required**; if you render your own, read that key.

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

`Consent::record()` also refuses an action the document's TYPE cannot carry, with
`IncompatibleConsentActionException`. Contract terms and privacy notices are `Acknowledged` (or
`ReAccepted` after a material change); `Granted`, `Withdrawn`, `Declined`, `OptInRequested` and
`Confirmed` belong to documents with `requires_explicit_optin`, and a `Confirmed` additionally needs
a preceding `OptInRequested` row for the same subject and key. The named transitions —
`accept()`, `withdraw()`, `object()`, `terminate()`, `confirm()` — ask their own half already, so
prefer them over `record()` unless you genuinely need the untyped write. The ledger is append-only,
so a row asserting a state the law has no shape for can never be corrected.

**No registration form to put a checkbox on?** Sign-in through an external provider raises
`Registered` with no form, so there is nothing to validate a tick against. Show an interstitial
after authentication and mount the bundled form as a first-use gate:

```blade
<livewire:legal-consent.reconsent-form
    :method="\Pushery\LegalConsent\Enums\ConsentMethod::FirstUseGate" />
```

It lists every mandatory document the subject does not hold — `Consent::firstAcceptance()` is the
same read if you build your own screen — and records each with that method. "Does not hold" is
wider than "never accepted": somebody who withdrew, declined or terminated holds nothing either,
and is asked again rather than served without an agreement. Do not reach for
`ReConsentGate` there: the ledger is append-only, and that value asserts an acceptance *after a
document changed*, which never happened.

`Consent::outstanding()` answers a different question and will not do: it filters on the notice
mode of a version CHANGE, and a first acceptance is not a change.

Set `legal-consent.gate.first_use` to `true` to have the middleware stop people until they have
been through that screen — and only together with the screen, or they land on a consent route that
tells them nothing is due.

Asking about several documents at once costs one read rather than two per key:

```php
$held = $user->hasAcceptedCurrentLegalMany(['terms', 'privacy']);
// ['terms' => true, 'privacy' => false]
```

**Alert on the scheduled sweeps.** Bind `Pushery\LegalConsent\Contracts\LegalConsentMonitor` (the
default binding discards everything) and each sweep calls `heartbeat(string $task, int $processed)`.
Three task names are the ordinary beat — `legal-consent:prune`, `legal-consent:dispatch-notices`,
`legal-consent:close-objection-windows` — and two are sent ONLY when a run failed:
`legal-consent:dispatch-notices.held`, a notice still owed because the audience exceeded
`notifications.max_recipients_per_run`, and `legal-consent:close-objection-windows.unproved`,
subjects that could not be deemed for want of a delivered § 308 Nr. 5 lit. b warning. **Alert on
those two by name.** The ordinary heartbeat is sent BEFORE the failure branch, so a run that held a
legally required notice back still beats as usual with the count it managed.

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

## Examples

A minimal, complete adoption: publish the config, author `resources/legal/terms/de.md` with
`version: 1.0.0`, run `legal-consent:publish terms de --active`, put `legal.consent` on the
authenticated route group, render `Consent::published('terms', 'de')?->html` on the public page, and
embed `<livewire:legal-consent.reconsent-form />` on the consent route. Schedule
`legal-consent:dispatch-notices` so a change with a grace period actually reaches subjects, and put
`legal-consent:publish --all --editorial` in the deploy script so a fresh database is never left
with empty legal pages.

The public page renders the stored bytes **unescaped**, which is the one place in this integration
where that is the right call:

```blade
{{-- $document came from Consent::published('terms', app()->getLocale()) in the route --}}
@if ($document !== null)
    <h1>{{ $document->title }}</h1>
    {!! $document->html !!}
@endif
```

`html` is exactly what the package sanitized before it froze the row, and the hash the ledger
carries is the hash of those bytes. So `{{ }}` there is not the safe choice, it is the wrong one —
the page shows visible tags — and sanitizing the value a second time changes the bytes, which
separates the page from the proof. Everything else on a consent screen is escaped as usual.

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
