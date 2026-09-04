# Upgrade Guide

This guide documents the changes you need to make when upgrading between
breaking versions of `pushery/legal-consent-for-laravel`. Because the package is
still `0.x`, a **minor** bump may contain breaking changes (SemVer `0.y.z`).

## 0.20.0 → 0.21.0

### The session-backed writes are rate-limited by default

The Livewire components' grant, withdraw, object and terminate actions and the web withdraw route
append to the same append-only ledger the JSON API does, and were the only ways in with no limit in
front of them. The new config key `routes.web_throttle` defaults to `'60,1'` — sixty writes a
minute per authenticated subject, on ONE budget however the surface is reached — and is applied by
the package itself, through the same `throttle` middleware your routes use (Redis-backed where you
called `throttleWithRedis()`). Past the limit the answer is `429` and no row is written.

**A config published under an earlier version already declares the `routes` block**, and the
shallow merge never delivers a new key into it — so the package applies the inline default and the
behavior changes for you either way, exactly as `routes.api_throttle` did. Add
`'web_throttle' => '120,1'` to your own `routes` block for a different limit, the name of a limiter
you registered with `RateLimiter::for()`, or `null` to switch it off.

## 0.19.0 → 0.20.0

### `ConsentManager` gains `firstAcceptance()`

If you implement `Pushery\LegalConsent\Contracts\ConsentManager` yourself, it no longer satisfies
the interface until you add:

```php
public function firstAcceptance(\Illuminate\Database\Eloquent\Model $subject, ?string $locale = null): \Illuminate\Support\Collection;
```

Nothing changes for an application using the bundled manager, the facade, or `ConsentFake` — all
three already have it. If you stubbed the interface in your own test suite rather than using
`ConsentFake`, that stub is what breaks: the class fatals at load with *"contains 1 abstract method
and must therefore be declared abstract"*. Extending `DefaultConsentManager` or switching the stub
to `ConsentFake` are both one-line fixes, and the second one survives the next method too.

### `legal-consent:doctor` exits 1 if you have published the config

This is the one change an installation meets **without opting into anything**, so it is worth
knowing before your pipeline tells you.

`gate.first_use` is a new key inside the existing `gate` block. Laravel merges a published config
**flat** — `array_merge` over the top-level keys of `legal-consent`, no recursion — so your
published `gate` block wins whole and the package default is not applied. The key reads as `null`,
which leaves the gating **off**; that direction is safe and nothing about your application changes.

What does change is the report. `doctor` lists a package key that never reaches your runtime as
lost, and a lost key is a failure, not a warning:

```
These keys exist in the package but NEVER reach your runtime config:
  - gate.first_use  (package default: false)
```

**Add the key to your published `config/legal-consent.php`** and the report is clean again:

```php
'gate' => [
    // …
    'first_use' => false,
],
```

If you have **not** published the config, there is nothing to do — the package default applies and
`doctor` is unchanged.

### A form already mounted as a first-use gate now lists documents

`ConsentMethod::FirstUseGate` has existed since 0.15.0 and the documentation named this exact tag,
but the bundled form sourced from `Consent::outstanding()`, which filters on the notice mode of a
version **change**. A first acceptance is not a change, so wherever a document had been published
silently the screen rendered nothing at all.

**If you already mount the form that way, it starts working at this upgrade.** That is the fix, and
it is also a change in what your users see: people who were waved through are now shown a screen
and asked. Expect the acceptances you were never collecting to start arriving — which is the point,
but it is not nothing on a Monday morning.

The set it shows is *the mandatory documents the subject does not hold*, which is wider than "never
accepted": somebody who withdrew, declined or terminated holds nothing either and is asked again.
Voluntary consents are never in it (Art. 7(4)), and informational pages never are.

⚠️ **Check the spelling of your mount.** A Blade template compiles into a file with no namespace, so
a bare `ConsentMethod::FirstUseGate` in the attribute throws `Class "ConsentMethod" not found`. Our
own documentation carried the short form in four places until this release. The form that works:

```blade
<livewire:legal-consent.reconsent-form
    :method="\Pushery\LegalConsent\Enums\ConsentMethod::FirstUseGate" />
```

### `gate.first_use` is opt-in, and it needs the screen

Setting `legal-consent.gate.first_use` to `true` makes `EnsureLegalConsent` also stop a subject who
owes a first acceptance. It is read strictly — anything but a literal `true` leaves it off, because
a gate that switched itself on for a truthy value would stop every subject of an application that
never asked for it.

**Turn it on together with the screen above.** Without one it is a dead end rather than a loop: the
consent route is allowlisted, so the subject lands there and is told nothing is due. `doctor`
reports mandatory documents published while the gating is off, and stays quiet once it is on —
that is a decision, and it leaves decisions alone.

**Doing nothing is a complete answer.** The default is `false`, and an application whose sign-up
records consent on the registration form does not need it.

## 0.18.0 → 0.19.0

### The change-notice proof is now written when the notice is DELIVERED

`ChangeNotification` implements `ShouldQueue`, so `Notification::send()` enqueued and returned —
and `legal-consent:dispatch-notices` then wrote the `legal_notices` proof row, stamped
`notified_at` on the version and exited 0. With a dead worker, a poisoned job or a refusing mail
transport that produced a green hourly run **plus an append-only row certifying a notice nobody
received**. `legal-consent:close-objection-windows` reads exactly those rows and binds each subject
to the contract change by silence, so the false proof turned into a deemed acceptance.

The proof now comes from the delivery side. A listener on `Illuminate\Notifications\Events\NotificationSent`
(channel `mail`) writes the row; a listener on `NotificationFailed` clears the version's
`notified_at` so the notice is owed again instead of sitting stamped.

**What you have to do: nothing, if your queue works.** The observable result of a working run is
unchanged — the same rows, for the same subjects, with the same content hash.

**What changes if it does not:**

- The command now says `Queued N notice(s) for delivery`, not `Dispatched`. It reports what it
  did.
- A version whose delivery fails becomes due again on the next sweep, by itself.
- **A queue that discards silently** — `queue.default=null`, or a worker that never starts — fires
  neither event. `notified_at` stays stamped and the version is not re-swept on its own; use
  `legal-consent:renotify` as documented. What is different is that there is no longer a false
  proof row, so `close-objection-windows` **refuses loudly** (exit 1, the objection window stays
  open) instead of binding the subject by silence.
- **If you test against this package with `Notification::fake()`**, a faked notifier never
  delivers, so no proof row is written. Pin the channel instead —
  `config(['legal-consent.notifications.channels' => ['mail']])` — and assert on `NotificationSent`
  events. The mail transport in a test environment is the array driver, so nothing leaves the
  process.

### Tamper-evidence now covers `tenant_id`

`LedgerHashChain` folds `tenant_id` into a row's canonical form. Until now it did not, although
`ConsentGate` filters on that column under multi-tenancy — so an acceptance could be moved from one
tenant to another with `prev_record_hash` untouched, and `legal-consent:verify-ledger` reported the
chain intact, with or without an HMAC key.

**Single-tenant installations: nothing to do.** The field is appended only when it names a tenant,
and `tenant_id` is `NOT NULL DEFAULT ''` — so every row of an installation that never enabled
`legal-consent.tenancy` produces the same canonical bytes it produced before, and every stored link
keeps verifying.

**Multi-tenant installations with `tamper_evidence` already on must re-chain.** Every
`prev_record_hash` written before this version was computed without the tenant, so
`legal-consent:verify-ledger` reports every chained row as broken from the first run after the
upgrade. Decide before upgrading:

- re-chain the ledger — `LedgerChainRepair::relink()` per `subject_token`, in id order, delete and
  insert inside one transaction (the shape `LedgerSubjectEraser` uses) — and record that you did it
  and why; or
- accept the discontinuity, note the upgrade date, and read every break at or before it as the
  upgrade rather than as tampering.

Rows written after the upgrade chain and verify normally either way.

### Tamper-evidence is bound to the database engine and the connection time zone

This is not new behavior — it has always been true and was not written down. The chain hashes
`accepted_at` as the string the driver returns. PostgreSQL renders a `timestamptz` with an offset
while MySQL and SQLite render none, and on PostgreSQL and MySQL that rendering follows the session
time zone, which Laravel sets from `database.connections.*.timezone`.

So once the first row is chained, each of these invalidates every stored `prev_record_hash` at once:

- restoring a dump onto a different engine;
- adding, changing or removing that `timezone` key.

`legal-consent:verify-ledger` then reports the whole ledger as tampered although nothing was
touched. Treat both as a re-chain event, exactly like `tamper_evidence_key`: fix the engine and the
connection time zone before the first chained row. The chain deliberately does **not** normalize the
instant — normalizing would change the canonical form of every row already written, on every engine,
and the only route back to a verifiable ledger is to rewrite every proof row, which is the one
operation the chain exists to make conspicuous.

### The ledger refuses an action its document type cannot carry

`Consent::record()` took an arbitrary action from an arbitrary caller and wrote it. The append-only
ledger therefore accepted an objection against a consent, a consent given by silence, a `granted`
on a privacy notice, and a double-opt-in confirmation with no request before it — rows asserting a
state the law has no shape for, in a table that cannot be corrected afterwards.

A compatibility matrix now sits at the write choke point and throws
`Pushery\LegalConsent\Exceptions\IncompatibleConsentActionException`. The named transitions
(`accept()`, `withdraw()`, `object()`, `terminate()`, `confirm()`) are unaffected — they already
asked their own half of the question.

**Check any direct `record()` call.** Contract terms and privacy notices are `Acknowledged` (or
`ReAccepted` after a material change); `Granted`, `Withdrawn`, `Declined`, `OptInRequested` and
`Confirmed` belong to documents with `requires_explicit_optin`. A confirmation additionally needs a
preceding `OptInRequested` row for the same subject and key.

### A document a consent points at can no longer be deleted

`legal_documents` had no delete protection, so the text a subject agreed to could be removed while
the consent row kept pointing at it — against the promise the README makes in as many words. A
`BEFORE DELETE` trigger (migration `0001_01_01_000021`) and a model hook now refuse it and throw
`LegalDocumentInEvidenceException`.

**Retire a version with `is_active = false`, which is the supported route and always was.**
Deleting a version nobody ever accepted still works.

Withdrawal follows from the same change: `withdraw()`, `object()` and `terminate()` fall back to
the version the subject actually accepted when no active version exists, so retiring a document no
longer makes Art. 7(3) unreachable.

### `legal-consent:publish` refuses an inverted notice timeline

`published_at < announce_from < enforce_from` was enforced nowhere, so a hard-gating change could
take effect **before** its own announcement and freeze `notice_period_days` at a negative number.
An unconditional guard now throws `NoticeTimelineInvertedException`, and the period is floored at
zero. An `--enforce-at` in the past with no `--announce-at` is still the documented immediate gate.

### The JSON API is rate-limited by default

The shipped default middleware carried no throttle, so four unauthenticated write endpoints pointed
at an append-only ledger with nothing in front of them. The new config key `routes.api_throttle`
defaults to `'60,1'` and is applied in the package's route file, ahead of your configured chain.

**A config published under 0.17 or 0.18 already declares the `routes` block**, and the shallow
merge never delivers a new key into it — so the package applies the inline default and the behavior
changes for you either way. Add `'api_throttle' => '120,1'` to your own `routes` block for a
different limit, or `null` to switch it off.

### Smaller behavior changes

| | |
|---|---|
| `vendor:publish --tag=legal-consent` | no longer copies the two opt-in migrations along with the required ones. Publish those with their own tags when you want them. |
| `legal-consent:dispatch-notices` | exits non-zero when a notice went out without its § 308 Nr. 5 lit. b mandatory content, and its `--dry-run` exits non-zero when the size brake would hold a version back. |
| `legal-consent:close-objection-windows` | leaves the window open when it could not deem every subject for lack of proof, instead of stamping it closed and reporting success. |
| all three sweeps | implement `Isolatable`, so `--isolated` works, and survive a fresh install whose migrations have not run yet. |
| `ConsentSettings` and `ReConsentForm` | `$locale` and `$status` are `#[Locked]`. If you set them from a parent component, pass them as mount parameters. |
| `LegalTextManager::releaseAll()` | answers `404` for a key the manager does not list, where it previously reached the source factory and threw. |
| the settings screen | no longer lists informational documents as outstanding consents. |
| `notice_periods.dcd_termination_days` | removed from the published config: it is the § 327r Abs. 3 free-termination window, fixed by statute, and nothing read it. |
| new key `legal-consent::ui.not_withdrawable` | in all seven bundled locales. |
| withdraw route | returns only to your own origin; a foreign `Referer` lands on `routes.home`. |
| new trait method `hasAcceptedCurrentLegalMany(array $keys)` | answers the `hasAcceptedCurrentLegal()` question for several keys in one read. |

### MySQL: the identity columns of `legal_documents` get a binary collation

Migration `0001_01_01_000022` gives `key`, `locale`, `tenant_id` and `version` a binary collation on
MySQL. The unique index over them decides whether two rows are the same document, and MySQL's
default collation is case- and accent-insensitive while PostgreSQL and SQLite are not — so `terms`
and `Terms` were one document on one engine and two on another. Nothing to do; existing rows are
unaffected unless you already relied on that insensitivity, in which case the migration fails loudly
on the duplicate rather than silently picking one.

## 0.17.0 → 0.18.0

### `subject_id` widens from an integer to a 64-character string

Both proof tables (`legal_consents`, `legal_notices`) held the subject's own primary key in an
`unsignedBigInteger`, which locked out any application whose users carry UUID or ULID keys. The
column is now `string(64)`, and migration `0001_01_01_000018` widens an existing installation in
place.

**For almost every application this is nothing to do.** Run `php artisan migrate`. No stored row is
rewritten, and **no tamper-evidence proof needs re-chaining**: the canonical proof form has always
hashed the string cast of every field — so an id that was a `bigint` and comes back as text hashes
identically. Both engine suites assert exactly that, against rows written before the type moved.

Three things worth knowing before you run it:

- **The migration rewrites two tables.** On a large ledger that is an `ALTER TABLE` of real
  duration; on PostgreSQL and MySQL the affected indexes are rebuilt with it. Plan it like any
  other schema migration on a big table rather than as a no-op.
- **The rollback can legitimately fail.** Once a subject with a non-numeric key has consented, no
  integer column can hold that proof, so `down()` refuses rather than dropping or zeroing it. If
  you have only ever used integer keys it reverses cleanly.
- **If you compare `subject_id` yourself**, a raw query that relied on numeric comparison
  (`WHERE subject_id > 1000`, an `ORDER BY` you expected to be numeric) now sorts and compares as
  text. Queries built through Eloquent or the query builder with `where('subject_id', $model->getKey())`
  are unaffected.

### `ConsentManager` gains `forget()`

If you implement `Pushery\LegalConsent\Contracts\ConsentManager` yourself, it no longer satisfies
the interface until you add:

```php
public function forget(\Illuminate\Database\Eloquent\Model $subject): \Pushery\LegalConsent\Support\SubjectErasure;
```

Nothing changes for an application using the bundled manager or the facade — and if you are
performing Art. 17 erasures with your own code today, this is what replaces it.

⚠️ **Check that hand-rolled version before you delete it.** It almost certainly does not re-link
the tamper chain, because the need for that is not obvious: the erased columns are inputs to the
row hash. If yours does not and you have `tamper_evidence` on, `legal-consent:verify-ledger` has
been reporting a break for every erasure you have already performed — those breaks are real and
this release does not repair them retroactively.

### The optional v1 backfill no longer skips non-numeric user ids

If you publish and run `0001_01_01_000004_backfill_v1_legal_acceptances`, note that it used to skip
any `users.id` that was not all digits — while the column was an integer, that check was the only
thing between a UUID and PHP casting it to `0`. It now accepts any value with a lossless string
form. If you ran the backfill BEFORE upgrading and your users have UUID keys, it imported nothing;
the run is idempotent by a `source = 'v1_backfill'` marker, so delete those rows (there are none)
and run it again after migrating.

### `legal-consent:publish --only-missing` and `--dry-run` now fail on a missing text

A registered document whose source resolves no text used to be a warning under `--only-missing`,
and the run finished green. Only a source whose empty state means *nobody has written it yet* is
warned about and skipped now — the bundled draft source, or your own source class if it implements
`Pushery\LegalConsent\Content\AwaitsAuthoring`. Everything else counts as provisioned and fails,
naming the document and the locale.

A missing Markdown file is the case this separates out: nobody is going to write that one. It is a
deployment missing a file, and skipping it leaves the empty legal page behind a green deploy — the
state this command exists to prevent.

Two runs change their exit code because of it:

- **`php artisan legal-consent:publish --all --only-missing --editorial`**, the line a deploy script
  runs, exits non-zero when a registered document has no text and its source does not declare
  `AwaitsAuthoring`.
- **`--dry-run`** now answers a textless source exactly as the real run does. It used to count every
  one of them as a warning and end at `0`, so the preview reported green for a run that could not be
  green.

**What to do before you deploy this.** Run the preview once against your own registry:

```bash
php artisan legal-consent:publish --all --only-missing --dry-run --editorial
```

Every line marked `x … no text` is a combination that will now fail. For each one, either author the
missing file, take the key out of the `documents` registry, or — if it really is waiting on an
editor — put it behind a source that declares `AwaitsAuthoring`. `legal-consent:doctor` names the
same gaps at any time and changes nothing.

## 0.16.1 → 0.17.0

### If your app has WireKit installed, your consent screens will look different

`legal-consent.ui.variant` is new and defaults to `auto`: with `pushery/wirekit` ≥ 2.26.0 installed,
the package now serves its **WireKit-native** views instead of the plain stubs. Previously that only
happened if you had run `vendor:publish --tag=legal-consent-wirekit`.

This is the fix for a silent defect — an unstyled view renders, so an application using WireKit
everywhere else was serving bare HTML on `/settings/consents` and on the re-consent gate with
nothing going red — but it is still a visible change on a screen you may have styled around.

**To keep exactly what you have**, pin the plain set:

```php
// config/legal-consent.php
'ui' => [
    'variant' => 'plain',
],
```

Nothing changes for an application without WireKit, and nothing changes for one that had already
published the WireKit tag: a published view is still checked before either set.

### If you implement `ConsentManager` yourself

The interface gains two methods — `requestConfirmation()` and `confirm()` — so a custom
implementation will not satisfy it until they are added. `ConsentFake` and the shipped manager
already have them.

`statusFor()`'s array shape also gains a `pending_confirmation` key. Reading code is unaffected;
a custom implementation should fill it, and a static analyzer will say so.

Nothing else changes: the two new `ConsentAction` cases and the new `ConsentMethod` case are
additive, no migration ships, and no existing row means anything different than it did.

### If you sign people in through an external provider

`registration.without_form_fields` is new. **Nothing changes unless you set it**: the default
`warn` is exactly what 0.16.0 already did. Set it to `refuse` and an unevidenced mandatory consent
raises `UnevidencedConsentException` instead of being recorded with a log line.

```php
'registration' => ['without_form_fields' => 'refuse'],
```

Do that only if your registration form uses the field names the package generates — the check can
look for nothing else, so an application with its own naming would start failing registrations
that are perfectly correct.

### If you published the framework-agnostic settings stub

Its withdraw button used to post to `#` — `withdraw_url` had no producer in the package, so the
fallback always won. The shipped stub now renders the form only when that key is filled, and fills
it from a new opt-in route:

```php
// config/legal-consent.php
'routes' => [
    'web' => true,   // POST /legal/consent/withdraw, behind ['web', 'auth']
],
```

**A stub you published earlier is your file and does not change.** If its button still posts to
`#`, either turn the route on and copy the `@if (($consent['withdraw_url'] ?? null) !== null)`
wrapper from the shipped version, or point the action at your own route.

The same applies to the **WireKit** settings stub, where the defect was sharper: it withdrew with
`wire:click`, which on a page with no Livewire component behind it does nothing whatsoever. If you
published that one, take the form and the `form="lc-withdraw-form-…"` confirm button from the
shipped version.

### If you published `config/legal-consent.php`

`ui` is a **new top-level block**, so `mergeConfigFrom()` delivers it in full and you need not do
anything. Add it to your published file only if you want to pin the variant.

`routes.web`, `routes.web_prefix` and `routes.web_middleware` are different: they sit **inside** a
block your published file already declares, and the merge is flat — so your `routes` block wins
wholesale and those three never reach runtime. The route simply stays off, which is the default
anyway. Add them by hand if you want it. `legal-consent:doctor` lists exactly this case.

## 0.16.0 → 0.16.1

**Nothing to do.** Both changes are fixes, and neither asks anything of you.

### If you use `ignoreMigrations()`

The three scheduled commands — `dispatch-notices`, `close-objection-windows` and `prune` — are no
longer registered when you decline the package's tables. Until now they were, and ran nightly against
relations that do not exist: a non-zero exit each time, and with schedule monitoring one entry in
your error tracker per run.

If you had turned the `schedule.*` flags off to silence that, you can turn them back on. Nothing
registers while the tables are declined, and the decision is made at boot with no database access.

### If you published a settings stub

`resources/views/consent-settings.blade.php` and its WireKit variant now render the document title as
a link when `legal-consent.document_url` is configured, and as plain text when it is not. **A stub you
published earlier is your file and does not change** — copy the `@if (($item['url'] ?? null) !== null)`
block from the shipped version if you want the link.

The stub headers also now list all seven keys the presenter delivers. The framework-agnostic one
additionally states what it never did: its withdraw form's `withdraw_url` is **not** supplied by the
package. As shipped that button submits to `#`. Point it at your own route.

## 0.15.0 → 0.16.0

**Almost nothing to do.** Everything in this release is additive except one refusal, and that one
only fires on code that was already producing an unverifiable chain.

### The tamper-evidence chain now refuses a row it cannot hash

`LedgerHashChain::hashRow()` accepts any object, and it used to fold anything without a string form
— an array, an object, a bool `false` — onto the same `S0:` an actual empty string produces. Four
different rows therefore shared one hash, and the class docblock promised the opposite.

Such a value now raises `UnhashableProofFieldException`, naming the field and the type.

**You are affected only if you hand it something that is not a database row.** Both shipped call
sites pass raw `DB::table(...)` rows, so the package's own paths are unchanged, and **no existing
hash moves**: null, string, int and float encode byte-for-byte as before.

The realistic case is an override. `DefaultConsentManager::latestChainedRow()` is `protected`, and
returning an Eloquent model from it is the obvious rewrite — but `LegalConsent` casts
`document_type`, `action`, `method` and `accepted_at` to enums and a date object, so four of the
eighteen proof fields hashed as empty, including which document, which act and when. That wrote a
link `legal-consent:verify-ledger` could never reproduce, and the command later reported tampering
on rows nobody had touched.

If you have such an override, return the row as the driver gave it to you:

```php
protected function latestChainedRow(string $token): ?object
{
    return DB::table('legal_consents')->where('subject_token', $token)->latest('id')->first();
}
```

If you ran with a model-returning override before this release, the rows written that way already
carry unverifiable links. The ledger is append-only, so they cannot be corrected — run
`legal-consent:verify-ledger` to see which subjects are affected and record the cause alongside
your retention notes.

### A new log warning, and nothing to change

Recording a mandatory document whose `legal_<key>` field is absent from the request now logs a
warning. It still records. If you see it, the `Registered` listener is running on a sign-in route
that has no registration form — the section above on `FirstUseGate` is the fix.

## 0.14.0 → 0.15.0

**Nothing to do.** This section exists because the composer manifest changed visibly, and a
dependency change is worth a sentence even when it asks nothing of you.

### The manifest now requires `laravel/framework`

It used to name twelve `illuminate/*` split packages. `laravel/framework` `replace`s every one of
them at the same version, so **the resolved dependency graph is identical** and no lock file moves.

The old manifest promised an install without the framework and did not keep it: shipped code calls
fifteen helpers that only `Illuminate\Foundation\helpers.php` defines — `config()`, `app()`,
`trans()`, `view()`, `request()` and ten more — at 185 call sites, and no split package provides a
single one of them. Such an install resolved cleanly and then fatalled at the first of those calls.
Nobody saw it because `orchestra/testbench` pulls the whole framework into the vendor tree.

If your application is a Laravel application, you already have the framework and nothing changes.

### If you sign people in without a registration form

New in this release: `ConsentMethod::FirstUseGate`, for the interstitial after authentication and
before first use. Two things are worth doing together:

First, capture the acceptance where it actually happens — in the interstitial template:

```blade
<livewire:legal-consent.reconsent-form
    :method="\Pushery\LegalConsent\Enums\ConsentMethod::FirstUseGate" />
```

Then turn off the `Registered` listener, which assumes a form validated the tick:

```php
// config/legal-consent.php
'registration' => ['listen_to_registered_event' => false],
```

Leaving the listener on without a registration form writes an acceptance row for every mandatory
document on the first provider callback, without a human having done anything.

## 0.13.0 → 0.14.0

### ⚠️ MariaDB is no longer supported

**0.13.0 supported MariaDB. This release withdraws that, and the withdrawal is the breaking change
in it.** If you installed 0.13.0 on MariaDB, read this section before upgrading.

The support should not have shipped. This package proves itself against the engines it targets —
SQLite, PostgreSQL and MySQL 8.4 LTS, the set Laravel Cloud runs — by re-running its whole
database suite against real servers. MariaDB was never in that set. It entered 0.13.0 as the
repair of a side effect rather than as a decision: 0.10.0's proof-column guard named the drivers
it could protect, MariaDB was not among them because Laravel carries `mariadb` as its own driver
name, and the fix chosen was to support the engine instead of to keep refusing it.

**What changes.** `ProofColumnGuard::assertSupportedEngine()` refuses the `mariadb` driver again,
so a fresh install stops at the proof-column migration with a named exception instead of
completing. The engine-specific trigger arms — the proof columns, the append-only triggers on
`legal_consents` and `legal_notices`, and the change-set freeze guard — no longer write MariaDB
bodies.

**If you are running 0.13.0 on MariaDB**, your database already carries those triggers and nothing
in this release removes them. Your options:

- **move `legal_documents` and the ledger to PostgreSQL, MySQL 8.4 or SQLite** — the engines whose
  trigger this package writes and tests against real servers. This is the supported path;
- **stay on 0.13.0**, and understand that the engine has no proving lane behind it: nothing
  re-measures those triggers on MariaDB, so a future defect there would not be caught;
- do **not** expect a rollback to work cleanly. `ProofColumnGuard::drop()` and the migrations'
  drop paths still name `mariadb` on purpose, so the triggers 0.13.0 installed can be taken off —
  but the install paths are gone, and re-running the migrations forward will refuse.

**What stays, and is the half worth keeping.** MariaDB is still recognized explicitly — as an
*impostor* on the MySQL lane. It reports e.g. `11.4.4-MariaDB`, which clears an 8.4 version floor
numerically, so both the test harness and the CI-lane pin assert engine identity from the server's
own banner rather than trusting the number. Pointing this package's MySQL suite at a MariaDB
server is a hard failure, not a silent pass.

## 0.12.0 → 0.13.0

### Your change notices now render in the package's own template

The one behavior change in this release that is on by default. Until now a change notice went out
inside **Laravel's global notification template** — greeted with "Hello!", closed with "Regards,"
and explaining what to do "if you're having trouble clicking", all resolved from **your**
application's translations. A German § 126b declaration therefore arrived wrapped in English
whenever your app's locale differed from the document's.

Nothing you have to do. If you had branded that template and want it back:

```php
// config/legal-consent.php
'notice_mail' => ['view' => null],
```

**Your proof rows are unaffected, and that is measured rather than assumed.**
`legal_notices.notice_body` is assembled from the mail's own line collections; the shell sets the
template and never touches them, so the body and its hash are byte-for-byte what they were. Only
one thing ever adds to that body, and only once you ask for it — see below.

### Name the declaring person (§ 126b BGB)

Optional, and worth doing. The package claims the durable medium and named nobody:

```php
// config/legal-consent.php
'notice_mail' => [
    'identity' => [
        'declarant' => 'Beispiel GmbH',
        'postal_address' => 'Musterweg 1, 10115 Berlin',
    ],
],
```

That line is appended to the notice **and therefore to the proof row**, which is the point of
putting it there rather than in the template. Notices written before you set it are untouched;
notices written after carry it, so their hashes differ from earlier ones — which is correct, and
worth knowing before you compare two rows and wonder.

**Multi-tenant applications should not use this block.** Bind `ResolvesNoticeIdentity` instead: a
single global declarant names the wrong legal person in every tenant but one, and a wrong declarant
is worse than an absent one.

### The three notifications are no longer `final`

`ReconsentRequired`, `LegalChangeInformational` and `DeemedConsentNotice` now extend a shared
`ChangeNotification`. If you were copying one to change its wording, subclass it instead and point
`notice_mail.notification.{mode}` at yours. Nothing about the existing classes changed for a caller
that does not.

### An info-only change under a regulated regime can now be refused at publish

If you publish `--info` with `--regime=p2b`, `--regime=eecc` or `--regime=gdpr`, the advance-notice
period for that regime is now enforced — previously an info-only change had no such check at all.
A P2B change with less than 15 days' standstill will now be **rejected** where it used to publish
silently, which is the point: Art. 3(3) makes a change implemented that way void, so a publish that
succeeded was the worse outcome.

Declaring a regime on an `--editorial` change is refused too. An editorial change owes no notice, so
its regime's period applied to nothing.

### The example `impressum` key is now `imprint`

Only the **example** in the shipped `config/legal-consent.php` changed. The registry is yours: if
you published the config — which the install instructions tell you to do — nothing moved, because
`documents` comes from your file.

It matters for one group: anyone who never published the config and relied on the shipped example,
whose text therefore lives at `resources/legal/impressum/`. Either rename that directory to
`imprint`, or publish the config and keep your own key. Both are one step, and the second is the
one to prefer anyway.

The rename fixes an inconsistency inside the package rather than a preference: every other key in
the catalog is English, `lang/*/titles.php` already headed this page `imprint`, and a consumer
whose directory is called `imprint` — as the starter kit's is — had to write a permanent exemption
into any check that compares their config against the package default, with "it is called something
else here" as the reason. An exemption whose reason is a naming collision reads like backlog
forever.

### `legal-consent:doctor` no longer fails on a list you deliberately keep shorter

If you pinned the doctor out of a CI step because it kept reporting `locales.1`, put it back. That
finding was wrong — see the changelog. Nothing you configured needs to change.

## 0.11.0 → 0.12.0

### Act on this FIRST: your info-only and deemed-consent changes are about to reach people

**Read this before you deploy, not after.** Until now, an info-only (`--info`) or deemed-consent
(`--deemed`) change published as a minor or patch bump — the shape this package's own documentation
told you to publish — selected **no subjects at all**. The sweep reported success, wrote no proof
row, and stamped its watermark anyway. Nothing surfaced it.

The audience is now mode-dependent: a gating change still goes only to the subjects its middleware
will block, and a non-gating change goes to **every current party**, because that is who the notice
duty attaches to.

**For a large installation this is a fan-out you have never seen.** If a hundred thousand people
hold your terms, the first sweep after this upgrade mails a hundred thousand people. Look at the
number before it leaves the queue:

```bash
php artisan legal-consent:dispatch-notices --dry-run
```

It reports the audience of every due version, sends nothing, and stamps nothing. A normal run now
names the same per-version count before it sends, so the number is never invisible after the fact.

**What is NOT at risk:** changes that were already swept. Their `notified_at` is stamped and the
sweep never revisits them, so nothing in your history goes out again on its own — reaching that
cohort takes the explicit `legal-consent:renotify` below. The automatic case is narrower: a change
that is due and not yet dispatched at the moment you upgrade.

If you want a hard brake for that first run, set a ceiling before you deploy:

```php
// config/legal-consent.php
'notifications' => [
    'max_recipients_per_run' => 500,
],
```

A version above it is **held back without being stamped** — nothing is lost, and the same notice is
still owed on the next run. Release it with `--force` once you have looked, or raise the limit.

**It ships as `null`, and that default is deliberate.** A limit that were on by default would
withhold a legally required notice from every installation that never asked for one — the exact
failure this release removes, and the expensive direction: under P2B Art. 3(3) a change implemented
without notice is void, while an oversized send is merely expensive.

### The cohort left behind, and how to reach it

Repairing forward does not help versions that were already swept: their `notified_at` is stamped,
so they are never considered again. If you published an info-only or deemed-consent change before
this release, **its audience was never notified** — and under P2B Art. 3(2) a change implemented
without notice is void (Art. 3(3)).

```bash
php artisan legal-consent:renotify terms de 1.1.0     # clears that version's watermark
php artisan legal-consent:dispatch-notices --dry-run  # see the audience
php artisan legal-consent:dispatch-notices            # send
```

Whether to renotify is your call: it is a decision about a legal event that has already happened,
and only you know what else you sent. The package will not do it on your behalf.

### Deemed consent now binds subjects it silently skipped

`legal-consent:close-objection-windows` shares that audience, so the § 308 Nr. 5 lit. b fiction was
also being recorded for nobody. It now binds every silent party — which is the correct behavior and
a real change in what appears in your ledger. A version whose objection window is still open will
produce `DeemedAccepted` rows on the first run after the upgrade.

### If you published your own translations

`notifications.php` gains one key: `contract.consequence_undated`, used when a version carries no
enforcement date. `contract.consequence` now interpolates `:deadline`. See the changelog.

### Run the migration

```bash
php artisan migrate
```

Two new tables, `legal_change_sets` and `legal_change_items`, hold the per-version description of
what a change actually changed. They touch nothing that exists: no column is added to
`legal_documents`, no row is rewritten, and a version with no description renders the same notice it
rendered before. On PostgreSQL and MySQL the migration also installs the triggers that freeze a
published description; on SQLite the model hook does that job alone.

### Nothing else is required

The description is opt-in. If you never author one, this release changes nothing about your notices
beyond the fixes above. When you are ready, see
[Saying what changed](https://docs.pushery.com/legal-consent-for-laravel/notice-modes/change-descriptions)
— and note that `change_items.required` ships **off**: turning it on makes a description a
precondition of releasing any change that owes a notice, which is a decision about your editorial
process, not a default a package should make for you.

### Deemed consent now needs `durable_medium.proof` on

If you use `DeemedConsent` **and** have `durable_medium.proof` switched off,
`legal-consent:close-objection-windows` now refuses to run rather than bind a population by silence
against no evidence. § 308 Nr. 5 lit. b BGB makes the delivered warning a validity condition of the
fiction, so a `DeemedAccepted` row without it is a consent record your own proof table contradicts.
`legal-consent:doctor` reports the same contradiction, so you find it before a window closes.

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

> **MariaDB was briefly supported in 0.13.0, and is refused again from 0.14.0.** Do not upgrade
> to 0.13.0 for this reason. See the 0.13.0 → 0.14.0 section at the top of this guide.

The refusal is raised **before** anything is altered, so a refused `migrate` leaves your schema
exactly as it was; there is no half-applied state to clean up. Your options:

- move `legal_documents` to PostgreSQL, MySQL or SQLite — the other engines whose trigger this
  package writes and tests against real servers;
- or stay on `0.9.x`, and know that your published rows are **not** protected by a database-level
  trigger today. The application-layer guard still refuses an edit through the model, but a direct
  `UPDATE` is not stopped.

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
'imprint' => [
    'source' => 'markdown',
    'legal_basis' => 'informational',
],
```

```bash
php artisan legal-consent:publish imprint de --editorial
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

## 0.4.0 → 0.5.0

`0.5.0` is a **minor** bump with two changes that alter existing behavior. **No new migrations
ship** — nothing in your schema changes.

### 1. The ledger models are no longer mass-assignable

`LegalConsent`, `LegalNotice` and `LegalDocument` carried `$guarded = []`. The append-only guard
refuses a row's *mutation*, and a forged proof row is an *insert*, so anything reaching
`LegalConsent::create($attributes)` could write or backdate one. All three models are fully guarded
now, and the package writes through its own curated attribute arrays.

If you wrote these rows yourself, `create()` and `fill()` throw `MassAssignmentException` after the
upgrade. Switch those calls to `forceCreate()` / `forceFill()` — the deliberate, auditable door —
or, better, route them through `Consent::record()` and its named transitions, which fill the proof
columns and the tamper-chain link for you. The break is loud and immediate; nothing fails quietly
here.

### 2. Registration rules, the displayed checklist and the recorded row resolve identically

The three used to answer the same question separately: the validation rules came from the config
registry, the checklist read published rows, and the recorder fell back to the default-locale
version of a mandatory document. A registration could therefore require a checkbox for a document
that was not published, or omit a control whose acceptance was recorded anyway. All three now use
the recorder's resolution — configured keys intersected with the **active** rows, with the
default-locale fallback for mandatory documents only (a voluntary consent may never be required,
Art. 7(4)).

Two consequences to check against your own install:

- **The consent section is dormant until you publish.** An unpublished document demands nothing and
  renders no control. If your registration form was relying on the config registry alone, publish
  the documents it asks for before deploying.
- **A document's legal nature is read from the published row**, not from its config entry, so a
  `legal_basis` that drifted from what was published can no longer decide whether a checkbox is
  mandatory.

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
exactly as it aborts runtime tampering. The package's own suite iterates
the live column list against the allowlist and fails if a new column is left
unprotected.
