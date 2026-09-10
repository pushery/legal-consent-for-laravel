# Changelog

All notable changes to `pushery/legal-consent-for-laravel` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and
the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.25.3] - 2026-09-10

### Changed

- **The manifest now declares the PHP extensions this package's code actually calls** — `ext-ctype`, `ext-filter`, `ext-hash` and `ext-mbstring`. No code changed. What changed is that Composer says so at install time instead of letting the package install onto a PHP that cannot run it. The gap had a single root cause: the `illuminate/*` split packages declare no extensions at all — only the `laravel/framework` metapackage names them — so a package built against the splits inherits none. `hash` is the one worth naming: it carries the audit trail's fingerprint chain, which is exactly where a missing extension must not first surface as a runtime error. A contract test now holds both directions, so an extension the shipped code calls must be declared and a declared extension must have a call site.

## [0.25.2] - 2026-09-10

### Added

- The notice-modes documentation now carries the admissibility matrix: which `NoticeMode` each `DocumentType` admits, and which three combinations the publisher refuses outright with the reasoning kept apart, since they rest on different law. It sits before the section on what a major bump forces rather than after it, because that is where the decision is made -- the rules were previously discoverable only from the refusal, by which time the mode is picked and the text is written. A contract test holds the table to the two predicates in the source, so it cannot drift from what is enforced.

## [0.25.1] - 2026-09-09

**A patch, and it unblocks a release of a legal text.** Nothing is required of you and there is no migration; if you held a document back from your publish step because releasing it failed, this is the version that lets you stop.

### Fixed

- **A multi-locale release no longer deadlocks against its own lock.** `LegalDocumentReleaser::release()` takes the activation lock and then publishes each locale inside one transaction — and the publisher took the *same* lock name again, once per locale. A Laravel lock is not reentrant: the inner instance carries a different owner token, so it could only wait out its five seconds and throw `LockTimeoutException`. Every release failed on its own serialization, mid-transaction. Reported from a consuming application's production error tracker.

  **It survived to production because both lock sites are guarded by the same condition** — a cache store that provides locks and is neither `array` nor `null`. On the stores a test suite runs on, *neither* side locks, so the whole path is invisible to a suite configured that way; on `redis`, `memcached`, `database` or `file` both sides lock and the release cannot complete. Configuring `legal-consent.cache.store` for production, which the package recommends, is what turned it on.

  The rule that resolves it was already written down for `LegalDocument::activate()`, which has skipped its own lock inside a caller's transaction since the day it was written: **whoever opens the transaction owns the lock.** A lock taken inside somebody else's transaction is released before that caller's commit, so it never spans the write it appears to protect — false comfort, not serialization. The publisher now follows the same rule; the releaser, being the outermost writer, still always takes the lock, and a direct `legal-consent:publish` is unchanged in every respect.

  The store condition, the shared lock name and the two timeouts had three copies between them, one per caller — spelled `! LockProvider || Array || Null` in one place and `LockProvider && ! Array && ! Null` in another. They now live in one place, so a reader who checks one is no longer believing all three. That place is a new `Support\ActivationLock`, and it is marked `@internal` on purpose: it is an extraction of code that already ran in those three callers, it offers a consuming application nothing it can use, and it carries no backward-compatibility promise. A new type in the dist is the reason this is a patch rather than a minor, so it is worth saying which one it is.

## [0.25.0] - 2026-09-08

**A minor bump, and every entry answers a report from an application that installs this package.** Nothing here requires you to change anything: all three additions are opt-in, and an application that ignores them behaves exactly as it does on 0.24.0. What they have in common is that each replaces a workaround a consuming project was carrying — so if you are carrying one of these, this is the release that lets you delete it. `UPGRADE.md` walks the two that need a line of code.

### Added

- **`statusFor()` reads the active set from the cache the gate already uses.** It kept a private `legal_documents` query, so a consuming application that renders a consent banner out of every layout paid an uncached lookup on every full page view of a signed-in member — for a fact that changes a few times a year. Reported from exactly that shape. The banner itself was moved onto this cache earlier; this method is the sibling that was left behind, and `hasCurrent()` right above it had already made the same move for the same reason. Measured before and after on the common path: two queries become one.

  **Only the global half is cached, and that is the whole design.** The per-subject ledger fold stays uncached: a stale answer there either strands somebody at a consent gate or waves them past it. The active-document set has none of that risk and needed no new invalidation to reason about — the model flushes this cache on any write to `legal_documents`, with an after-commit listener on top for the release transaction's ordering. It also removes a divergence rather than adding one: the gate that enforces and the status map that reports it now read the same set, where before the gate read the cache and the map queried live.

  Two smaller consequences. The rows come back with the cache's wider column set instead of four hand-picked columns, so nothing downstream can meet an unset attribute. And the order is now `(key, id)` rather than whatever the engine returned, which is the order every other surface built on this cache already renders in.

- **The re-consent form can answer the question the gate actually asks.** With `gate.first_use` on, `EnsureLegalConsent` holds a subject on the **union** of what changed and what was never accepted, while a mount answered one of the two — so an application with a single consent route, which is the shape `routes.consent_name` describes, sent every first-use subject to a form rendering zero documents while the gate kept holding the next request. The package's own doctor already named the outcome: a dead end, not a loop. Reported from a consuming application that had written the missing branch itself, and the point of the report was that the branch is not an application decision — it inverts what the middleware computed two lines earlier, so every host with OAuth, magic links or invitations writes the identical one. Mounting with `answersGateQuestion` now renders both sets on one screen. What makes it more than a concatenation is the provenance: the method lands in an append-only ledger row, so each document is recorded with the question it actually came from — `first_use_gate` for one never accepted, `re_consent_gate` for one that changed since — within a single submit. A key in both sets is recorded as a re-consent, the stricter of the two statements. A mount that names a `method` is unchanged in every respect.

  It is a separate mount argument rather than a null `method`, and that was forced by a defect worth naming: a `#[Locked]` typed property mounted as null reads back as null on the mount request and as the property's own PHP default on every request after it. The form would have rendered the union, the subject would have ticked both boxes, and `submit()` — a later request — would have been back to the narrower question, dropping one acceptance behind a success message. Livewire also assigns a mount argument straight onto the public property of the same name, so a null against a non-nullable enum never reaches `mount()` at all.

- **The consent checkboxes can bind to a Livewire property.** Both shipped variants restored their state from `old()`, which is correct for a POST form and inert on a Livewire screen — `old()` is empty across a commit, so the component never learned the box had been ticked. A consuming application reported it after adopting `ConsentWordingLink` and finding it still had to keep its own checkbox surface for the two screens that matter most: the re-consent interstitial and the registration form. Passing `bind` now names a property holding one entry per field, and each control carries `wire:model="{bind}.{field}"` with no `checked` attribute beside it — the two together fight each other on every re-render, which is the defect the report described. Omit `bind` and nothing about either stub changes. The array is keyed by **field** rather than by document key, because `field` is already what the id, the `name`, the error bag and `RegistrationRules` use, and the one control that is not a document — the Art. 8 age attestation, named by its bare key — would otherwise be bound to a property no rule validates. Two consequences are yours rather than the package's, and both are documented at the seam: under a binding the stub contributes no tick at all, so the never-pre-checked guarantee (CJEU C-673/17) rests on the bound value starting `false`; and the opt-in accept-time hash goes inert, because Livewire submits no form and a hidden input is never sent.

## [0.24.0] - 2026-09-06

**A minor bump: fifty-three fixes and four migrations to run.** The install range does not move — `laravel/framework ^13.0`, `php ^8.4` — and no public API changed shape. Two of the fixes change behavior an installation can feel, so read `UPGRADE.md` before you migrate: a legal document published at a `0.x` version starts gating the subjects it was always meant to gate, and `ignoreMigrations()` no longer switches off the three scheduled sweeps. The one-active-version guarantee now holds on MySQL and SQLite as well as PostgreSQL — check for a duplicate active version before migrating, because the new index refuses to be created while one exists.

### Fixed

- **The WireKit consent checkboxes link the document name inside the sentence, like the plain ones do.** The capability shipped in one of the two views: the plain stub turned the title into the link where it appears in the snapshotted wording, and the WireKit twin kept repeating the name as a second link underneath — the exact shape the change was made to replace, for every application that renders the themed variant. Both now resolve it the same way, and the fallback is unchanged where it is still needed: a wording that does not contain its own title (a sentence saying "AGB" for a document called "Allgemeine Geschäftsbedingungen") keeps the separate link, because a text that cannot be reached breaks the clickwrap requirement (§ 305 Abs. 2 BGB). `aria-describedby` follows the shape rather than the presence of a URL — a link inside the label is already part of the control's accessible name, and describing the field by it reads the title twice on every checkbox of a registration page.

- **`legal-consent:publish --all` no longer fails on a document nobody has written yet.** A draft-backed source says its empty state means the text has not been authored — there is no file anywhere to freeze, because the text is written in the admin. The command treated that as a failure and exited 1, so a correctly configured installation with one such document could not put `--all` into a setup step or a seeder at all, out of a command whose own help says "idempotently". The distinction already existed and was gated on `--only-missing`, which conflated two different questions: which combinations to visit, and whether a source's empty state is a defect. The second is a property of the source, so it no longer moves with the flag. What has NOT changed is the case the strictness is for: a provisioned source with no text — a markdown-backed document whose file is missing from the deployment — is still a failure, and nobody is going to write that one in an admin screen. A skipped document is named individually rather than counted, so it cannot sit unpublished behind a green deploy. One visible consequence: the summary line now always carries its "without text" segment instead of growing one under `--only-missing`, so a log grep sees the same shape from every run.

- **A morph alias written as a number no longer takes two paths down with it.** `Relation::morphMap(['404' => User::class])` is a configuration Laravel accepts, and what PHP then holds is the integer `404` — a numeric string becomes an int the moment it is an array key, so `getMorphClass()` returns an int. Two closures in this package declared `: string` around exactly that call and therefore violated their own return type: the batch token resolve every notice sweep runs, and the objection-window sweep's `whereIn`. Both were fatal on the first subject, and the cast one line below the first of them — written for this case and commented as such — could never be reached to do its job. `mapKey()` needed the same treatment, because it hands the alias straight to a string parameter, so the path that mints a token for a subject who has none failed even after the closure was fixed. All three are cast now, and a guard holds the shape rather than the three sites: the pattern is idiomatic, it passes review, and it is fatal only under a configuration most installations never use.

- **An axe pass over every screen, in a real browser.** There was none: `axe`, `axe-core` and `injectAxe` appeared zero times across the tests and both manifests, with the control on the same scan finding twelve mentions of WCAG — so the suite talked about accessibility in a dozen places and asked a browser nothing. Every a11y arm that did exist is hand-maintained and point-wise, and has to be extended by hand when a screen is added; this is the only check on that surface which does not. It runs over the registration form, the re-consent screen, the settings screen, a published legal page and the WireKit admin editor, each asserting its own landmark first so a redirect cannot pass as a clean screen, and behind a control that axe was injected at all — an injection that stopped happening would report every screen clean forever.

  It found a critical violation on its first run, in the demo host rather than the package: two inputs carrying a placeholder instead of a label. Both are fixed, because a sweep measuring the host's defect instead of ours is worse than no sweep.

  Two measurements came with it. The registration form reflows at 320 CSS pixels with no sideways scrolling. And the consent checkbox's hit target, with no CSS applied, is 13 px inside an 18 px label — **both** under the 24 px minimum, which contradicts the assumption that the enclosing label rescues it. The sizing note in the stub says so now, with the numbers.

- **`acknowledgment` in prose, `acknowledgement` where it is an API value.** The shipped surface carried the British spelling in 41 files while `SECURITY.md` used the US form and the Packagist description used the British one — two shipped files contradicting each other. The guard could not see any of it, because the word was on neither of its lists: a word list is a check whose scope is exactly what somebody remembered, and it fails silently in the only direction that matters. Most of those occurrences are not a spelling mistake at all: `acknowledgement` is the `legal_basis` value a consumer writes in their own config, the name behind three translation keys and a DOM id, and renaming it would break every consumer that already wrote it for the sake of a dialect. Those shapes are masked individually, each with the reason, so genuine prose is still caught; the prose moved to the US form. Released changelog entries are left alone — they are the record of what was said at the time, and the scan now binds where a rule written today still can.

- **The translation note describes the floor rather than the present.** It said five of the seven bundled locales still announce the UI kit's own strings in English — correct when it was written, and no longer true: measured against the version installed here, all five resolve in their own language. The sentence described a version range in the present tense, which is the tense that goes stale without anyone noticing. It now says what holds at the declared floor, what changed since, and gives a one-line command to check the install in hand instead of trusting either sentence.

- **The banner countdown stops re-rendering once a second for a value that changes once a day.** Both countdowns took the component's default seconds place while the threshold beside them is a week and the value on screen is "N days left", so every authenticated page with an open change repainted an element per second, per document — a DOM write and a CSS transition each time, and the only continuous client work the package shipped. It buys nothing at that resolution. The seconds place is off at both call sites now, and the comment there says plainly what that does not do: the component's interval is unconditional, so what goes away is the rendered change per tick, not the timer. Narrowing the interval is reported upstream rather than worked around here.

- **Doctor says when the document cache resolves to a database-backed store.** The advice existed and only a docblock carried it: the enforceable set is asked for four times in a documented request, and on the framework-default `database` store each of those is a SELECT against the cache table, so the per-request memo hands part of its saving straight back. A consumer on a default install is in exactly that state, has done nothing wrong, and nothing said so — the command's checks mentioned neither cache nor store. It reads the resolved store's **driver** rather than its name, because a store called `database` may be backed by anything and a store called anything may be backed by the database. Reported, never enforced: `database` is a reasonable choice on a small install, and a check that refuses a working configuration is a check people stop running.

- **The touch-target guidance sits where the touch targets are.** It lived in the admin editor stub — one textarea and two buttons, seen by an operator at a desk — and was absent from the registration checkboxes, which carry one native checkbox per document, are what most consumers publish first, and render at roughly 13x13 px by default in Chrome. That is half the 24 px minimum, on the one screen a visitor cannot get past. The stubs still ship no CSS, so this is guidance rather than a rule; what changed is that it is now readable at the point where it applies.

- **`locale` and `version` fit the formats they hold.** They were `varchar(10)` and `varchar(20)`, and both are narrower than values that are perfectly well-formed: `ca-ES-valencia` is a valid BCP 47 tag at fourteen characters, `1.0.0-alpha.1+build.123` valid SemVer at twenty-three. The tight one is `locale`, and it is tight enough to hit ordinary traffic — `zh-Hant-TW`, `zh-Hans-CN` and `sr-Latn-RS` are each exactly ten, so a consumer serving Traditional Chinese sat on the limit and any variant subtag went over it. The failure was also invisible where people develop: SQLite ignores a `varchar` length completely, so the value round-trips at full length there, while MySQL 8.4 in strict mode refuses the row with `1406 Data too long` — green on the laptop, broken on deploy, the same divergence class the binary collation closed for identity. Migration 000028 widens them to 35 and 64 on MySQL and PostgreSQL and deliberately skips SQLite, where there is nothing to widen and an `ALTER` would be a full table rebuild that drops the proof triggers and turns the partial one-active index back into a full one. Run the new migration.

- **The MySQL half of the activation lock is proven rather than assumed.** Two files said in prose that MySQL has no partial index, so one-active-version rests on the application layer and a second active row would simply stand. That stopped being true when the generated `active_identity` column and its unique index arrived, and nothing tested the claim in either direction — the only arm nearby asserts the column exists, which a broken index would satisfy. A second active row is now inserted past the application layer and the database refuses it, with the count checked before and after so a refusal for any other reason cannot read as success.

- **The Spanish acceptance sentence names the document its own heading names.** The heading read `Boletín` and the sentence said `la newsletter`, so one locale called the same document two different things — and it was the only one of the twenty-one shipped locale/type pairs where the title does not appear in its own sentence, which is what lets the name itself become the link. Spanish readers therefore got a layout no other locale gets: a separate link below the sentence instead of an inline one. Dutch is the other locale that translates the term, and it translates both halves; Spanish now does too. The class that locates the title claimed a measurement over "fourteen published rows (seven locales, two documents)" — the third consent type had never been in it, and that is exactly where the gap sat. The shipped language files are walked on every run now, so the count is measured instead of quoted.

- **A status region that also takes focus is announced once now, not twice.** Both legal-text-manager stubs carried `role="alert"` with `aria-live="assertive"` on the element that receives focus after a release — so assistive technology said the message twice: once as a live-region interruption, once as the accessible name of the newly focused element. Dropping the focus move instead is the worse trade, because it drops a keyboard user on `<body>` immediately after an irreversible action; the move is already immediate, so politeness costs no urgency. Both twins now match the package's other focus-moving regions, and a new arm holds the rule over every shipped view rather than over the one screen that got it wrong — which is how it went wrong in the first place, with each stub read against its twin and neither against the rule. On the registration checkboxes the same duplication had a different cause: where the document title appears inside the consent sentence, its link sits inside the `<label>` and is already part of the accessible name, so describing the field by it read the title twice on every box of a registration page. The description is now emitted only for the shape that renders the link as a sibling.

- **Every control that waits on the server says it is waiting.** Nothing in the shipped Livewire stubs reported an in-flight request — not the re-consent submit, which is on the one screen a subject cannot leave without acting. Fifteen of the sixteen controls across both variants now carry `aria-busy` for the duration, and the re-consent submit adds a visible localized label beside it. The sixteenth is the WireKit release button, which tears down its own dialog in the same click and is out of the document before the response arrives — the wait there is reported by the status region the close reveals. The busy state is deliberately not `wire:loading.attr="disabled"`, the common idiom: `disabled` blurs the control the user just activated, so focus falls to `<body>` for the whole in-flight window — the same failure the status regions' focus move exists to prevent, except on every request rather than in one edge case. The WireKit twin looked like it already had this and did not: `loading-target` only scopes a spinner switched on by the component's `loading` prop, which the call never set, so the attribute rendered nothing at all. Both variants now carry the identical pair, so a publish flag cannot change how a wait is reported.

- **Two comments no longer explain a mechanism that does not run on their page.** The settings stubs said their live region was kept permanently in the DOM because a region inserted together with its text is not announced. That is true of the Livewire views, which morph the region in place, and it is false here: withdrawal answers a POST with a redirect, so the message arrives in a fresh document alongside the region carrying it, and a live region present at page load has no mutation to report. The banner stub already said this correctly about itself; these were the copies that did not. The roles are kept for what they do buy — naming the message in reading order, where it sits directly after the heading.

- **The quality-bar paragraph no longer claims something the release does not do.** The README and `CONTRIBUTING.md` both said the full gate includes mutation testing and runs before every release. The gate recipe chains statics, coverage, browser and asset checks — no mutation step — and the pre-push hook says so in its own words. Mutation runs on its own schedule and is deliberately not part of a release: a score is a measurement to act on, not a number to hold a version behind. Both paragraphs say that now. Two dead translation keys are gone from all seven locales — `changes.heading` and `changes.impact` are rendered nowhere, because the notice emits the operator's own headline and impact text directly; rendering them instead would have changed a mail body that gets hashed into an append-only row, which is a different decision than removing two strings nobody reads. `cache.prefix` reaches the configuration reference, and a config comment that pointed *"above"* at a value fourteen lines below it no longer points anywhere.

- **The re-consent gate keys the one list that shrinks, and two guards stopped being able to miss a component.** The pending list loses an entry on every successful submit, so the node at position zero changes identity — and the server renders no `checked` attribute, which means a ticked box lives only as a DOM property with nothing in the markup to reset it. A box that survived that swap would be a pre-ticked checkbox the subject did not tick in this round, which is the Planet49 condition (C-673/17) the view's own header cites. Both view sets now key on the document rather than the position. The client-trust ratchet derived its component set from a hand-entered list while calling itself *"every public property of a shipped component"*; it reads the provider's registrations now, so a fifth component cannot arrive unaudited. And the branch that refuses a tick made while its document was not outstanding — the only protection left once there is no render-time hash to compare — had no arm entering it: without it the submit does not merely record the wrong version, it dies on an undefined key.

- **Four places where the framework already had the answer.** A change-description line collapsed its whitespace by hand with `\s+`, which does not cover the class of character that makes this matter: a zero-width space survives it and leaves a line that is non-empty and invisible — in a change notice whose body is hashed into an append-only proof row. `Str::squish` strips it, along with the soft hyphen and the byte-order mark. All eleven console commands now carry `#[AsCommand]`, which lets the runner learn a command's name without constructing the class: without it every `artisan` invocation eagerly builds all eleven, measured at 0.68 ms and 181 KB per process, paid by `artisan --version` and by `schedule:run` every minute. `optimize:clear` now reaches the rendered documents — without that registration it left them behind for up to `cache.ttl` seconds (86 400 by default), so an operator clearing the cache kept being served the old legal text. And a docblock that described a model hook sat stacked above a constant, where PHP attaches only the last one: it was invisible to reflection and to every editor, and it appeared to document something else.

- **Five arms that could not fail now can, and one of them was hiding a fact about the schema.** A branch guarded on the database driver short-circuited with `expect(true)->toBeTrue()` — and the fast suite IS that driver, so on every ordinary run it asserted a tautology and reported a pass, indistinguishable from the real check running. Its comment chose that over a skip deliberately and inverted the visibility it was after: a skip shows as `s`, a tautology shows as a green test. The reason it gave was also wrong — the engine carries the two triggers it claimed were absent, so the raw check it was excusing itself from works there and now runs everywhere. Elsewhere: two arms asserted only a zero exit from a command that reports its findings by warning and returns success either way; one asserted the absence of two strings the component cannot produce, over a screen that could have rendered nothing at all; and one asserted a control's absence with a different needle than its presence, so the negative half could pass because that string appears nowhere.

- **The suite now counts what it does not run, and holds the switch that makes the database half mandatory.** Forty-four call sites skip a test — most of them correctly, because a WireKit view cannot render without WireKit and a PostgreSQL arm cannot run without PostgreSQL — and nothing anywhere counted them: no skip budget, no `--display-skipped`, no ceiling. A skipped arm and a passing arm both read as *not a failure*, so a suite that skips a little more each month reports the same green either way. There is a budget now, with a floor as well as a ceiling: the floor is what fails if somebody clears the ceiling by deleting the skips instead of the reason for them. The second half is sharper — removing the one environment variable that turns an unreachable database from a skip into a failure is a one-line deletion that leaves every guard green while two hundred arms go quiet, and one guard rests a whole arm on that variable being set while only ever checking the services beside it. The lanes are discovered rather than listed, so a new one arrives held.

- **Three localization guards can no longer empty themselves.** Every arm of the parity suite walks a constant of seven locales, so an eighth catalog added by hand was visible to none of them — not file parity, not key parity, not placeholder or plural-selector parity — and shipped to consumers unchecked, which is the state the suite exists to prevent. The set is held in both directions now: the constant stays, because seven is also a promise and discovering the set alone would let somebody delete a locale and stay green. The terminology and typography guards walk hand-written key lists and skip a key that is not in the catalog, correctly, so a key renamed across all seven takes parity with it and leaves those guards walking one term fewer, forever, reporting no violations either way. Each now records what it actually read and fails when that set shrinks, and the typography guard derives its locale set from what ships instead of restating it.

- **A VCS consumer can no longer receive build output, a dependency tree or editor state.** The release audit classifies every top-level entry as SHIP, STRIP or IGNORE, and only the first two were held against the archive-exclusion list — its own header said *"every entry classified STRIP"*, and the guard was written to match it. All fourteen IGNORE entries answered `unspecified`: none of them is tracked, which is why it never showed, and `git add -f build/bundle.js` is one command after which `git archive` hands that file to every consumer pulling the package as a VCS repository, with all four guards green. The two entries classified BEFORE they exist were worse off still: the arm skipped any path that was not on disk, so the one case pre-classification exists for was the one case never verified. Both forms of every entry are probed now — the path and a child under it — because a bare `dir/` leaves the directory itself `unspecified`, and `git check-attr` answers for a path that is not there.

- **The helper three CI guards depend on is itself guarded now, and writing its fixture found a second hole.** `resolveYamlAliases()` resolves a lane's YAML anchors before the PHP, database and browser pins are read — and none of the three red proofs used an anchor, so switching the resolution off left every arm green while two of the four lanes dropped out of the scan entirely. It reddens two arms now. The fixture also exposed a shape the image extractor could not see at all: an anchor declared on the `image:` line itself, where the pattern's `\S*` stops at the space and the line contributes nothing. No lane writes it that way today — which is precisely why nothing was red, and why it would have been a lane silently exempting itself from every version check the day somebody did.

- **Two guards that could not fail are now able to.** The enum-label arms all built their expectation out of `$case->label()` — the thing under test — so they asked whether the keys resolve, whether they are distinct, and how many there are. Swapping `ReviewState`'s two `match` arms left all three green while the admin screen reported *"Reviewed"* for a draft nobody had read: a compliance statement, wrong, on the surface whose whole job is to say whether a human signed off. Six permutations of `BlockingReason` do the same to a release refusal, and there was no second line of defense — those keys appear nowhere else in the suite. The expectation now comes from the case NAME, which no `match` arm can move. And the most prominent leak arm had no positive control at all, so green over 248 files and green over none read identically; its own neighbor plants four leaks and says *"the belt must BITE"*. It counts what it scanned, inside the loop it guards.

- **A release that adds a feature and touches no documentation now says so.** The portal sync is triggered on the paths the documentation source lives under, so it fires correctly — and a release that adds a screen without touching a page fires nothing at all. Measured at 0.23.0: 94 files changed since 0.22.0, none of them documentation, while the release's headline was a new feature; the page describing that screen said nothing about it on the published site. The trigger cannot know better, because it sees paths and not intentions. The release now compares its own `### Added` section against what moved in that source since the previous tag and prints a warning when the answer is nothing. It is deliberately a warning: plenty of additions genuinely need no page, and a release gate that blocks on a judgment call is one people route around — what was missing is that "deliberately left alone" and "nobody thought about it" produced the identical silence.

- **The retention sweep reads an index instead of scanning the whole ledger.** `legal-consent:prune` filters on `accepted_at` (and `sent_at` on the notice ledger), and no index led with either — both sat fourth in a `subject_doc_time` index, unreachable as a prefix. The pages that DELETE were never the problem, which is why this looked fine: in an append-only ledger `id` and `accepted_at` correlate, so those are cheap primary-key range scans. The expensive page is the last one, the one that finds nothing — and in normal operation a scheduled run deletes nothing at all, so that page is the entire cost of every run, growing with the ledger. Measured on PostgreSQL 18 over 50 000 rows: a sequential scan removing all 50 000 rows by filter, 1 471 shared buffers and 4.684 ms, against an **index-only** scan with zero heap fetches, 11 shared buffers and 0.029 ms. Migration 000027 adds `(accepted_at, id)` and `(sent_at, id)`; `id` is the second column because the sweep pages by it. Run the new migration.

- **The shipped WireKit views name the floor the package actually applies.** Five places told a consumer they needed `pushery/wirekit >= 2.13` or `>= 2.17.1`, while the provider serves the WireKit variants only from `2.26.0` — so somebody who followed the comment got the plain stubs and no explanation. The lower numbers are not merely stale: below 2.26.0 WireKit announces its own screen-reader strings in English whatever locale the page is in, which on a legal deadline is not cosmetic. A new arm derives the floor from `WIREKIT_MINIMUM` and fails on any lower version named in a shipped file, exempting only a number a sentence marks as history.

- **A legal text the HTML parser gives up on is refused, instead of being hashed as a fragment.** The sanitizer called `libxml_clear_errors()` on its way out, so every parse message went unread — including the one that says the parser stopped. libxml has a hard nesting limit of 256 elements: measured, 500 004 bytes of input came back as 6 375, with no error, no warning and no log. `content_hash` is taken over the sanitizer's output, so the append-only row then asserted that fragment was the text somebody agreed to while the operator had published something else — the one question this package exists to answer, answered wrongly and silently. The refusal keys on the error LEVEL rather than on a message, because recovering from messy markup is what an HTML parser is for: unclosed tags, stray closing tags, unquoted attributes, smuggled entities, CDATA, processing instructions and NUL bytes each parse in full and are unaffected, and all seven are held by arms. On the editor screen it arrives as a status line rather than a 500, on save and on a machine translation alike — the translator's output travels the same pipeline and is not privileged.

- **Delivery proof is promised for the three modes that announce, not for all four.** The README and the portal index said *"four notice modes … each with delivery proof"* — and the portal contradicted itself inside one sentence, *"an editorial change is silent … each with durable-medium delivery proof"*. `SilentEditorial` is not in the notice sweep's selection, so it never writes a proof row: there is nothing to prove, which is the point of the mode. A new arm derives the count from the sweep's own `whereIn`, so a mode joining or leaving it fails a test rather than a reader.

- **The Art. 17 reasoning for keeping `notice_body` names the code that actually carries it.** The comment justified retention with *"`renderProof()` takes a version and no subject"* — there is no `renderProof()`, and the method that does the work, `WriteNoticeDeliveryProof::render()`, IS handed the subject and passes it to `toMail($notifiable)`. The conclusion still holds and the reason is narrower than it claimed: the three shipped notifications build the body from the document alone and never read the notifiable. That puts the guarantee in those classes rather than in a signature — and makes the existing caveat sharper, because subclassing `ChangeNotification` is a documented seam.

- **`ignoreMigrations()` no longer switches off three legally owed sweeps.** The schedule gated `legal-consent:dispatch-notices`, `legal-consent:close-objection-windows` and `legal-consent:prune` on `self::$runsMigrations`, and that flag carries two meanings: its own docblock offers it for publishing the migrations and running them from the host app — the tables then EXIST — while the 0.16.1 upgrade note read it as declining them altogether. The schedule believed the second reading and the documentation advertised the first, so a consumer on the documented publish path silently lost the change notices owed under § 308 Nr. 5 lit. b, the closing of every objection window (silence never bound), and retention under Art. 5(1)(e). No error, no warning, tables present. The registration now asks whether the tables are **there**, which is the fact the commands depend on; it sits inside `callAfterResolving`, so a web request never pays for the check, and a fresh install self-heals on the first `schedule:run` after `migrate`. `ignoreMigrations()` keeps its documented meaning and no new configuration appears — splitting the flag in two would have added public surface, and narrowing its meaning would have made the published publish path unusable.

- **On MySQL the ledger verifier compares identities as bytes, so the two forgeries it exists to find are visible again.** Its subject↔token fold groups in SQL, and GROUP BY follows the column's collation — every collation Laravel configures by default is case- and accent-insensitive and PAD SPACE. Measured against a real MySQL 8.4: two `subject_token` values differing only in case collapse into ONE group, and `subject_id` `'5'` against `'5 '` likewise, so the fabricated second chain and the stolen token arrived in PHP already merged and were never reported. The grouping is now `CAST(… AS BINARY)` there — the same repair, for the same reason, that the proof trigger already carries. PostgreSQL's default collation is deterministic and SQLite compares BINARY, so neither needed it, and that is asserted on both engines rather than assumed: the defect was invisible precisely because the suite that covered it runs on SQLite.

- **The floor guard now pins the constraint, not merely that a reason exists.** `narrowFloorReasons()` matched KEY SETS, so a package that already carried a reason could be narrowed further and every arm stayed green — measured: raising `league/commonmark` from `^2.9` to `^2.20` passes the whole file while locking out 2.9 through 2.19. `laravel/framework` and `php` were safe only because each had a literal arm of its own, which left the single package with an advisory floor as the one a raise could slip through. `narrowFloorPins()` holds the exact constraint of every narrowed floor in both directions, and each pin's version has to appear in its own reason so the two cannot drift.

- **A legal text in another language than the page now says so.** `lang=` appeared zero times across all fifteen shipped views, while `hreflang="de"` was already on the checkbox link — so the render path and the data both worked, and the attribute a screen reader actually switches its voice on was the one missing. `hreflang` names the language at the far end of a link; WCAG 3.1.2 asks about the language of the PARTS, and the part here is the consent sentence itself: spoken with the page's phonetics, "Ich akzeptiere die Allgemeinen Geschäftsbedingungen" on an English page is not something a subject can be said to have understood (Art. 7(1)). It is not an edge case — a mandatory document published only in the default locale still binds and appears in its own language, and a RETIRED holding is deliberately shown in the language the subject read it in. `ConsentPresenter::settingsFor()` selected `locale` and dropped it before building the entry, so no settings view could declare it at all; it is in the entry now, and every consent surface in both view sets — checkboxes, settings, the re-consent gate — declares the language where it differs from the page and stays silent where it does not.

- **Thirteen counts in shipped prose said something the code had stopped doing, and the derivable ones are now derived.** Each was true when written and stopped being true when something was ADDED — the direction nothing notices. The README and the portal index called the ways to record consent "three" (Way D arrived in 0.20.0, and the README said "four" seventeen lines further down) and promised "a worked example each" for the notice modes (three of the four have one). `optional-features` said all four capabilities turn on with a single config switch — the double opt-in has none, it is on as soon as `double_opt_in.confirm_within` carries a duration. The configuration reference and migration 000001 still said MySQL and SQLite leave one-active-version to the app layer, which migration 000025 ended. `ProofColumnGuard` named two migrations that rebuild a guarded table inside the wrapper (there are three), the service provider said two publish groups write to the host `users` table (one does, by dropping columns), `LegalDraftWriter` claimed three writers of `source_hash` and then listed two, `ReleaseOptions` said eight fields where there are seven, the notice-table migration named a column that lives on the ledger, and the command reference documented `publish {key}` for a `{key?}` argument and omitted `dispatch-notices --force`. The portal's `statusFor()` example showed seven of nine keys — missing exactly the two 0.23.0 was cut for. Five new arms derive the counts they can from the enum, the page headings, a live `statusFor()` call, the migration tree and the registered commands, so the next addition fails a test instead of a reader.

- **An objection and a termination now say so, on both components.** `ConsentSettings::object()`, `ConsentSettings::terminate()`, `ReConsentForm::object()` and `ReConsentForm::terminate()` wrote their append-only ledger row and left `status` empty with `statusNonce` at `0` — measured on all four. The aria-live region the package ships for exactly this (WCAG 4.1.3) therefore said nothing, and the `x-effect` focus move keyed off that nonce never fired, so focus dropped to `<body>` (WCAG 2.4.3) right after the most irreversible action on the screen. The control that was clicked is usually gone from the next render, so the honest reading was that nothing had happened; the natural response is a second click, and a second click writes a second row that the ledger cannot take back. Two new keys — `objected_confirmation`, `terminated_confirmation` — ship in all seven locales. Six existing arms called these four methods and could not see the gap, because every one of them asserted a database row or an HTTP code, where a silent success and a loud one are identical: there is now an arm per action over the MESSAGE, and a reflection ratchet that fails on any future public component action that neither announces nor is classified as deliberately silent.

- **The legal-text editor refuses an unknown key, an unpublished locale, and a date it cannot read.** Three defects on one screen, all reachable through the mount the documentation tells you to write. An unconfigured document key reached the source factory, which has nothing to resolve for it, and left as a 500. A locale outside `legal-consent.locales` was accepted silently, and then `releaseDeemed()` published the CONFIGURED locales — measured: an editor mounted on `fr` took the text, reported it reviewed, released `de`, and told the operator that »terms« was released; the text just written was not published and nothing said so. Both refuse with 404 now, the same refusal the manager already made. The date fields were worse than either, because they bound people: `CarbonImmutable::parse()` turns `x` into TODAY and `31.02.2026` into `2026-03-03`, so an objection window could open and close on the same day, or move by days, and be frozen into an append-only proof row — and its loud path threw `InvalidFormatException`, which extends `InvalidArgumentException` and no `catch` in that method takes, so a mistyped date left as a 500. A format parse alone does not fix it: `createFromFormat('!Y-m-d', '2026-02-31')` also returns `2026-03-03`. The value is parsed by format AND round-tripped, so a date that does not print back as what was typed is refused by name — every unreadable field at once, through the status line the screen already uses for a rejected window. An empty field still means *not stated*, unchanged.

- **One active version is now enforced by the DATABASE on every engine, not only on PostgreSQL.** Migration 000001 gave PostgreSQL a partial unique index and said in its own comment that *"MySQL/SQLite rely on the app-layer guard"* — a lock taken on the configured **cache** store. `array` is process-local, `null` grants every lock, and a store that is no `LockProvider` cannot be asked at all; when it could not serialize, the package logged a warning and wrote anyway. So on two of three engines the one question the ledger answers — which text was in force when somebody agreed — rested on an optional, swappable, non-durable component, and two concurrent releases could leave two rows with `is_active = true`. SQLite now takes the identical partial index (it has supported them since 3.8.0) and MySQL the generated-column equivalent, so a second active row is impossible whatever the cache does. The lock is unchanged and becomes honest: it turns a constraint violation into an orderly wait rather than standing in for the constraint. Run the new migration.

- **The change-description freeze no longer opens on a partially loaded row.** Both guards asked `getOriginal('state')`, which reads out of the model's original attributes — so a row fetched as `select(['id', …])` has no state there and the answer is `null`. `null` is not `Published`, so the write went through on a frozen row: the one the guard exists to refuse. Partial selects are ordinary in this package, and on SQLite these hooks are the only protection, because the database triggers cover PostgreSQL and MySQL. The guards read the stored row when the caller did not load it, and refuse a row they cannot find rather than waving it through.

- **The shipped source no longer describes how the package is checked.** Measured against the published v0.23.0 dist: twelve source files talked about the nightly mutation run, and one carried an internal finding number — all of them in comments, which for a library ARE the product, because a consumer reads them in `vendor/`. Every one of those comments said something true and worth keeping; they say it now in terms of the code rather than of a test lane. The guard behind them read five markdown files and never the shipped code, which is why a path scan over that code found nothing: the leak was vocabulary, not paths.

- **A lawful retention prune no longer leaves the ledger reading as tampered.** The writer sets `root_proof` only on the row that OPENS a chain. A prune that removes that row makes the next one the opener, and the re-link moved its pointer to genesis without giving it the proof — so `legal-consent:verify-ledger` reported *"chain opened after the boundary with no root proof"*, its wording for a fabricated chain, on an Art. 5(1)(e) deletion an operator is obliged to perform. It did not heal, because the next prune no longer collects that token: its first link already points at genesis. The re-link writes the proof now. `root_proof` sits outside the hashed columns beside `prev_record_hash`, so this changes no row hash, and unkeyed it is a no-op.

- **A tamper-evidence check that cannot run no longer passes in silence.** With `legal_ledger_markers` absent — an installation that never ran migration 000024 — the root-proof check is skipped, and `legal-consent:verify-ledger` reported such a ledger as intact under a note promising that editing history requires the secret. Measured: a fully fabricated chain (fresh token, genesis link, no root proof, for a subject who never consented) verified clean, while the identical row is caught once the marker exists. The command now says the check did not run, on both outcomes. It deliberately does not fail — treating a missing marker as boundary zero would demand a proof from every chain in a database the feature never reached, which is the shape of guard that gets switched off rather than read.

- **`legal-consent:doctor` reports the tamper-evidence posture.** Two states it never named: the feature switched on without a key (the operator asked for the guarantee and holds the weaker half of it), and an unstamped chain-root boundary. Both are gated on the feature being ON, because it is off by default and a warning that fires for every consumer who never asked for it is one nobody reads.

- **The ledger verifier no longer reads a clean multi-tenant ledger as forged.** The subject token is minted per tenant, so one person legitimately carries a different token in each — while the binding check read past the tenant scope and grouped on `(subject_type, subject_id)` alone. Two perfectly correct rows therefore reported a second chain fabricated for that subject, and a multi-tenant installation could never verify green. The same pass fixes the opposite error in the sibling check: it counted distinct `subject_id` with no `subject_type`, so a token carried on two different subject CLASSES with the same primary key counted as one subject — which is exactly the theft that check names in its own message.

- **The one-active-version migration is safe to run twice, and repairs an index a table rebuild degraded.** Both arms checked the wrong thing, in opposite directions. On SQLite `CREATE UNIQUE INDEX IF NOT EXISTS` treated the index NAME as the constraint — a table rebuild reconstructs indexes from a schema state that carries no `WHERE` clause, so the partial index comes back FULL under the same name, binding every version of a key rather than only the active one, and `IF NOT EXISTS` then left it there. The predicate is read back from the catalog now. On MySQL there was no check at all: there is no `ADD COLUMN IF NOT EXISTS`, so a second run died on a duplicate column while the SQLite arm beside it was idempotent.

- **Both operator manuals name the third failure-only heartbeat.** They said "alert on those two by name" and listed `legal-consent:dispatch-notices.held` and `legal-consent:close-objection-windows.unproved`. There are three: `legal-consent:dispatch-notices.deficient` has been sent since 0.19.0 and neither document mentioned it. It fires when a change notice went out WITHOUT its mandatory content — § 308 Nr. 5 lit. b makes the silence warning a validity condition, so silence cannot bind against those notices — which makes it the one alarm an operator most needs and the one they were told nothing about.

- **`Consent::forget()` is described per ledger.** The Boost skill said it strips five columns "from both ledgers". It strips five from the consent ledger and two from the notice ledger; the other three are not columns there. The erasure is unchanged — the description reached further than the code, in the direction that matters for an answer given to a supervisory authority.

- **The Livewire suggestion names all four components.** `composer suggest` listed `ReConsentForm` and `ConsentSettings` and left out `LegalTextManager` and `LegalTextEditor`, which are the only in-app way to author and release a legal text. That is the line on which an application decides whether to install Livewire at all.

- **The proof trigger no longer freezes a column the database derives.** `ProofColumnGuard` builds its trigger from every column except an operational allowlist, which is what keeps a proof column added later covered without an edit — and it therefore also enumerated the generated column the new one-active-version index needs on MySQL. That column is computed from `is_active`, so from then on every legitimate activation changed a "frozen" column by definition, and a fresh MySQL installation could neither deactivate a document nor publish a second version. Generated columns are excluded now. Nothing is given up: a generated column cannot be written directly at all, and every input of that expression — `key`, `locale`, `tenant_id` — stays protected.

- **The affected-subject index carries `tenant_id`.** Migration 000013 promises the notice sweep walks the population linearly, and `AffectedSubjectResolver` adds a `tenant_id` predicate whenever tenancy is enabled — one no index carried, so on a multi-tenant deployment that filter was a residual scan and the promise held only for the single-tenant case. It is the trailing column rather than the leading one: leading would serve the multi-tenant query and make the index unusable as a prefix for every single-tenant installation. Run the new migration.

- **`UPGRADE.md` no longer tells you that `0.5.0` ships no migrations.** It shipped two. The paragraph is corrected in place, marked as a correction, and now also states what migration 000014 does to a SQLite installation: dropping a foreign key there rebuilds the table, so a trigger you wrote yourself on `legal_consents` is dropped and not restored.

- **The enforceable set has a deterministic order.** Nothing ordered it, so the banner listed pending changes in whatever sequence the storage layer returned — one order on PostgreSQL, another on SQLite, and a different one again the moment an index was added. It is ordered by document key now.

- **A legal document published at a `0.x` version is now enforced, instead of counting as held by everyone.** The held-major fold uses `0` as its sentinel for *holds nothing*, so at `major_version = 0` the gate's `held >= major_version` was `0 >= 0` — true for a subject with an empty ledger. The document blocked nobody, `outstanding` was false so it was never offered either, and the settings screen reported it as accepted to people who had never seen it. A withdrawal was invisible for the same reason: an ending action drops the holding to the same `0`. Reachable through the ordinary path — `setVersion('terms', '0.9.0')` is accepted and `major_version` has no floor at 1 — so a product numbering its terms before 1.0 shipped a gate that enforced nothing. A holding is now a PRESENCE rather than a number, derived from the same single ledger fold, so the three states a `0.x` document could not tell apart — never accepted, accepted, withdrawn — are distinct. See `UPGRADE.md`: subjects who pass today will be gated after this upgrade.

## [0.23.0] - 2026-09-05

**A minor bump: two additions on the admin surface and four fixes, none of them breaking.** The install range does not move — `laravel/framework ^13.0`, `php ^8.4` — and nothing in `UPGRADE.md` applies, because no public contract changed shape. Two of the four fixes are behavior a consuming application can observe: a deleted document stayed enforceable until its cache entry expired, and a retention sweep could hit a driver error while repairing the tamper chain on a large ledger.

### Added

- **The legal-text editor can release with an objection window.** A deemed-consent change — one that binds if the objection window closes without an objection (§ 308 Nr. 5 BGB) — was implemented end to end: the schema carries the window, `LegalDocumentReleaser` takes it, and `legal-consent:close-objection-windows` closes it. No shipped screen could drive it. `LegalTextManager` states that the deemed and info-only modes belong to *"the editor controls or the CLI, not a button on an overview grid"*, and that sentence named two homes while only the command line had it — so an application with an admin UI had no in-app path at all, and a consuming application rebuilt the screen itself. The editor now carries three dates and the two flags, in both the plain and the WireKit stub. It stays out of the overview grid deliberately: a release that binds people by their silence is a per-change legal call, and the editor is the surface where somebody has actually read the text.

  A window that runs backwards or falls short of the statutory lead time comes back as a status message naming the package's own numbers, not as an exception — which is the point of having it here rather than only on the CLI.

- **`statusFor()` says WHICH version was accepted, and when.** Two keys, `accepted_version` and `accepted_at`. Until now the map carried `accepted_major`, which is a major — `2.0.0` and `2.7.3` are the same number — and no date at all, so building "what did this person agree to, and when" meant fetching `history()` as well and folding it per document key. That is not a one-liner (the history arrives ascending, `action` is a string rather than the enum, and the caller has to know `isAccepting()` is the right filter), and `history()` reads the whole append-only ledger with no limit — a cost that grows with data nobody deletes. The fold already happened inside the gate, so the values were there; they simply were not passed out.

  **Both are null once the holding ends**, in step with `accepted_major` dropping to 0. Keeping the last accepted version would report a withdrawn opt-in as still standing on a text, which is the same defect one column over that the withdrawal-aware fold was built to end. The historical fact stays in `history()`, whose job it is.

  `ConsentFake::statusIs()` fills the two keys when a test leaves them out, so existing fakes keep working and can never hand back a row shape the real manager would not produce.

### Fixed

- **Deleting a legal document no longer leaves it enforceable for the rest of the cache TTL.** The enforceable-set cache is flushed on every write to the table, and the flush finds the locales it has to clear from two places: the locales the application declares, plus the ones currently published. A DELETE removes the row that made the second half discoverable — so if `legal-consent.locales` is not declared, the locale of the document just deleted was in neither list by the time the listener ran, and its cached set survived. The gate went on enforcing a version that no longer existed until the entry expired.

  It bites in exactly one configuration, and not by accident: leaving `locales` undeclared is what lets a document be published in any language, which is the same setup a sibling guard was written for. The deleted row's own locale is now cleared from the model, which still carries the attribute at that point even though the table no longer does.

- **A retention sweep no longer risks a driver error while repairing a chain.** Both operations that lawfully remove ledger rows — the Art. 17 erasure and `legal-consent:prune` — rewrite the surviving rows to re-link the tamper chain, and both write them back with a multi-row INSERT. The erasure sized that write by a placeholder budget and arrived at 37 rows; the retention sweep chunked at a hardcoded 500, which at 24 columns is 12 000 placeholders. SQLite has shipped builds with `SQLITE_MAX_VARIABLE_NUMBER` at 999. A subject with 47 consent rows already produced a single statement binding 1104 of them.

  Where it bites, it bites as a hard driver error inside the transaction that repairs the chain, on the largest ledgers only, in a scheduled task. The budget is now derived from the row's own width in the one place both rewriters read, so the two cannot disagree again and the number stays correct as columns are added.

- **A record accepted exactly on the retention boundary is kept, not deleted.** The boundary between the two was never asserted, and it is the one this command promises: `legal-consent:prune` deletes what is *older than* the retention period, so a record at exactly the cutoff instant stays. It has always behaved this way; nothing failed if it stopped.

- **A record orphaned through only one subject column is now pruned.** Eligibility reads `subject_id is null OR subject_type is null`, but every case that reached it nulled both together, because that is what the package's own erasure does. A consuming application that clears just one — a morph-map migration, a foreign-key purge — left a row that no longer belongs to anybody and that the sweep would never collect, with the IP address and user agent still in it. Both halves of that rule are now held by a test.

## [0.22.0] - 2026-09-05

**A minor bump carrying two breaking changes and one security fix**, which SemVer `0.y.z` allows. The first break is a return type: `blockingLocales()` hands back a `BlockingReason` instead of an English sentence, so a release screen can be translated at all — the sentences themselves are unchanged and still reachable as `$reason->value`. The second only reaches you if you construct `DefaultConsentManager` by hand: its registry argument is nullable now, and a literal `[]` means *nothing is registered* rather than *do not filter*. The security fix closes a forged consent planted as a new chain at the public root, which `verify-ledger` reported as intact. Both are written up in `UPGRADE.md`, and the install range does not move: `laravel/framework ^13.0`, `php ^8.4`.

### Changed

- **The legal-text manager speaks the reader's language.** Two places bypassed the seven shipped catalogs and printed English whatever the locale. The review-state column rendered `ReviewState::…->value` — literally `draft` and `reviewed`, storage tokens that only look like labels in English — and the release column printed six hardcoded English sentences returned by a support class. Both now come from the catalogs, so a German compliance screen reads German. Reported by a consuming application; its own suite ran in English, where the output is correct, which is why nothing was red.

- **`blockingLocales()` returns a `BlockingReason` instead of a sentence.** `LegalDraftSet::blockingLocales()`, `ChangeItems::blockingLocales()` and `LegalReleaseNotReady::$blocking` now carry the enum. Returning finished prose left an application one way to translate a release screen — use the sentence as a lookup key — which breaks silently the first time the wording moves. The sentences themselves are unchanged and still reachable as `$reason->value`, so the exception message and anything logging it read exactly as before; only the type moved, and a type moving is something your tooling points at. See `UPGRADE.md`.

- **A failed `legal-consent:verify-ledger` now says whether the chain is keyed.** On a keyed installation that verdict has two causes and they call for opposite responses: tampering is an incident, a wrong or rotated `tamper_evidence_key` is a deployment mistake that has touched no row. The break list alone cannot tell them apart, because a key that does not match reproduces none of the stored links and every chained row mismatches — which is also what rewritten history looks like. The shape does tell them apart, so the command now says it: a key mismatch breaks **every** chained record, tampering breaks only the ones it reached. Check the secret before treating the output as an incident.

- **The documentation now says how strong the tamper-evidence secret has to be.** At least 256 bits from a cryptographic random source, never a passphrase, and kept out of the database backup. The reason is specific to this design rather than general advice: every chained row stores its content in the clear beside a MAC over that content, so a copy of the table is an unlimited supply of known plaintext/MAC pairs — the exact input an offline guessing attack wants, running at the attacker's pace with nothing here able to observe it. Both the published config and the documentation carry the generating command.

- **Creating an account no longer queries the database when no legal document is registered.** `RegistrationRules` resolved the locale chain and issued one `SELECT` per candidate *before* looking at the registry, then walked zero keys of the map it had just paid for. An age-gate-only installation — a real configuration rather than a contrived one — paid one to two queries on every `POST /register` for nothing. Calling it a micro-optimization undersells where it sits: this is the request that creates an account, the one an application can least afford to have depend on the database more than it must.

- **The enforceable-document cache key carries a payload version.** `0.5.0` changed what that key holds, from a serialized Eloquent collection to a list of primitive attribute rows, and kept the key name. On a shared cache store that is a hazard in one direction: code from `0.4.x` reading the newer payload hands the array straight through its `: Collection` return type and throws an uncaught `TypeError` on the per-request gate path. It cannot happen between two releases at or above `0.5.0`, where the shape has been stable — but a rollback or a mixed fleet crossing that boundary reproduces it every time. Versioning the key makes the two payloads unable to meet. Your enforceable-document cache is cold once after upgrading; the orphaned entries expire on their own TTL and nothing has to clean them up.

### Fixed

- **A Markdown table in a legal text renders as a table.** `RenderPipeline` built a bare CommonMark converter with no extensions, so a table came out as a paragraph full of pipe characters. The sanitizer had permitted `table`, `thead`, `tbody`, `tr`, `th` and `td` all along — the allowlist was describing a capability the package did not have, which is why nothing ever went red. It lands on exactly the wrong text type: a privacy notice or cookie policy is the document that lists recipients, purposes and retention periods in a table, and it was only visible on the rendered page. `TableExtension` alone, so autolinking, strikethrough and task lists stay off. If one of your sources contains a table, its rendered HTML changes and `check-drift` will say so — see `UPGRADE.md`.

- **The change-notice sweep could resolve zero subjects and notify nobody.** `AffectedSubjectResolver` scoped subjects to the version's tenant with `where('tenant_id', $version->tenant_id)`. On a model that was just created that attribute is `null` — it only becomes `''` after a refresh — and `where(…, null)` compiles to `IS NULL`, which matches no row of a `NOT NULL DEFAULT ''` column. With multi-tenancy enabled, a sweep run against an un-refreshed version therefore found nobody, dispatched nothing, and reported success. The tenant filter stays conditional on purpose: with tenancy off, a version belonging to a tenant must still reach subjects whose consents sit in the shared bucket.

- **Publishing a document and activating it now commit together.** The version row was persisted, and activation ran afterwards outside any transaction. When activation lost the lock race it threw `LockTimeoutException` and left the row behind — persisted and inactive, in an append-only table. That version was then unrepublishable: the next attempt met *"already exists with different content — bump the version before publishing"* for a version the operator never successfully published, and only a manual edit cleared it. The activation lock is taken outside the transaction, which is load-bearing rather than stylistic — `activate()` skips its own lock when a transaction is already open, so wrapping without lifting the lock out would have removed the serialization while looking like it added safety.

- **A cache flush now reaches every locale a document was published in.** The flush walked `legal-consent.locales` from config. That list is empty whenever the key is absent or not an array — and in exactly that case the publisher permits documents in *any* locale, so the one configuration that lets you publish in any language was the one where a publish, and `legal-consent:cache-flush`, forgot nothing at all. Not one missed locale: every one of them, while the command reported success. The published locales are now read back from the table, which is one `SELECT DISTINCT` paid on a publish or an explicit flush and never on the request path.

- **The legal-text manager announces a release the same way in both stubs, and moves focus afterwards.** The plain stub announced a release assertively; the WireKit twin announced the same irreversible act politely — so whether a screen reader interrupted for it depended on which view the application had published. Both now use `role="alert"` with `aria-live="assertive"`. The release is also confirmed in a modal that closes itself, which left focus on `<body>`: a keyboard user dropped at the top of the document immediately after an action that cannot be undone (WCAG 2.4.3). Both stubs now send focus to the status region.

- **The source-preview cache survives a hardened serializing store.** `LegalSourceRenderer` cached the rendered `Document` as an object. An application running a serializing store under `cache.serializable_classes` reads a cached object back as `__PHP_Incomplete_Class`, so the read guard rejected it on every hit and the renderer re-rendered and re-wrote on every call — a permanent silent cache miss that costs the full render each time and reports success throughout. It now caches primitive fields and rebuilds the object, the way the enforceable-document cache already did. The cache key is unchanged and needs no version: both directions across the change degrade to a cache miss rather than an error, unlike the sibling cache whose mismatch was an uncaught `TypeError`. Existing entries are re-rendered once and replaced. This is the preview and drift-check path, never the published text a subject sees.

- **The registration checklist no longer offers controls when nothing is registered for registration.** The checklist intersects the published documents with the configured registration registry, so the three sides — what the form shows, what the rules validate, what the recorder writes — resolve the same set. That intersection was skipped whenever the registry came back empty, because an empty array was read as "no registry was configured". Setting `ask_at_registration => false` on *every* document produces exactly that empty registry, and it is a real configuration: it says ask nobody at sign-up. The form then rendered a checkbox for every published document while the rules validated none and the recorder wrote none — a ticked box that goes nowhere, on a compliance surface. `DefaultConsentManager`'s fourth constructor argument is now `?array`, where `null` means no registry was supplied (a manager built by hand keeps the old behavior) and `[]` means nothing is registered. If you construct that class yourself and pass a literal `[]`, you were getting "show everything" and will now get "show nothing" — pass `null` for the old meaning.

- **`vendor:publish --provider` no longer reaches the opt-in publish groups.** `publishes()` merges every path into one per-provider map whatever tag it was given, and a provider named without a group hands that whole map back — so `--provider="…\LegalConsentServiceProvider"`, offered as a first-class interactive choice and the obvious thing to type, published the three groups the tag design deliberately withholds. Two of them write to the host `users` table: one drops columns, one backfills historical rows. The backfill's own no-op guard does not help the people who need it, because an installation without `terms_accepted_at` is precisely the one it skips. Those groups now live on a provider of their own; every documented `--tag=…` keeps working untouched, since tags are global.

### Security

- **A forged consent can no longer be planted as a new chain.** The chain root was a public constant, so an actor with nothing but `INSERT` on `legal_consents` could fabricate a consent nobody granted — a single row for a fresh `subject_token` pointing at that constant — and `legal-consent:verify-ledger` reported the ledger intact. The verifier resets its expectation to the root at every new token, and a row that is the only one of its chain has its hash compared against nothing. The row that opens a chain now carries `root_proof`, an HMAC over the token that only a holder of `tamper_evidence_key` can produce, and the verifier requires it.

  Existing chains are untouched and stay valid: a marker records the point from which proofs are required, so below it a missing one is history rather than a forgery. The marker carries its own MAC, because a boundary an attacker could raise is one they would move past their own forgery. That MAC doubles as the secret's identity — if it fails on a database nobody has touched, this environment holds the wrong key, and the command now says so instead of leaving it to be inferred from a break list that looks like tampering.

  Set no `tamper_evidence_key` and nothing changes; without a secret there is nothing to prove with. See `UPGRADE.md`.

## [0.21.0] - 2026-09-04

**A minor bump whose one behavior change arrives without opting in**, which SemVer `0.y.z` allows: the bundled Livewire actions and the web withdraw route are now rate-limited, on the same budget as the JSON API. Written up in `UPGRADE.md`. The declared install range does not move — it has been `laravel/framework ^13.0` and `php ^8.4` all along, and is now pinned so it cannot narrow quietly.

### Changed

- **The supported floor is Laravel 13.0 and PHP 8.4, and nothing may raise it quietly.** Neither number moves here — `require` has read `"laravel/framework": "^13.0"` since the manifest named the framework at all, and `"php": "^8.4"` since the first release. What changes is that both are now pinned literally, so an install range cannot narrow as a side effect of something else.

  The distinction being defended: `require` decides who can **install** the package, which makes it a promise to you rather than a report on what our test toolchain resolved. Those two come apart in one direction — a suite proves the package from the lowest version its toolchain will install, and below that the floor is read from the code rather than exercised. That gap is worth stating, and it is stated here; what it is not is a reason to raise the floor, because doing so locks out applications the package runs on perfectly well.

  `league/commonmark` keeps its `^2.9`, narrower than its major base on purpose: six advisories, four of them HIGH, are patched in 2.9.0. A security floor is about you, a toolchain floor is about us, and only the first belongs in `require`.

- **The README carries a `mutation ≥80%` badge.** It is held to the floor the shipped `composer mutate` script enforces, in both directions, so it can neither claim more than is checked nor go stale the next time that floor moves. There was deliberately no badge before: one that overstates what a gate enforces is worse than none.

### Security

- **The session-backed ledger writes are rate-limited, on one budget.** `routes.api_throttle` put a limit in front of the JSON API because behind it sits an append-only ledger with no de-duplication and no pruning by default, so an unlimited caller mints permanent rows. That reasoning is about the ledger, not about JSON, and the other ways in had nothing in front of them: measured, three `grant` calls on the settings screen wrote three `granted` rows and three `withdraw` calls after them three `withdrawn` rows. A Livewire request is one POST with a CSRF token, as scriptable as any other.

  The new `routes.web_throttle` (default `'60,1'`) covers the web withdraw route and the grant, withdraw, object and terminate actions of both bundled components, through the same middleware the route runs — including the host's Redis-backed one where `throttleWithRedis()` was called. One budget for the whole surface, keyed on the authenticated subject and kept apart from any `throttle:` the application applies to the same subject elsewhere. Past the limit the answer is `429`, and no row is written. `null` switches it off; a config published before the key existed receives the inline default, exactly as the API limit does.

## [0.20.0] - 2026-08-28

**A minor bump that carries one breaking change**, which SemVer `0.y.z` allows: `ConsentManager`
gains `firstAcceptance()`, so a custom implementation of the interface no longer satisfies it. The
bundled manager and `ConsentFake` already have it, so an application that uses either is unaffected.
Written up in `UPGRADE.md`, together with the one behavior change an installation meets without
opting in.

### Added

- **A first acceptance finally has a shipped surface.** `ConsentMethod::FirstUseGate` has described
  this screen since it was introduced — "the only place a first acceptance can happen when there is
  no registration form to put a checkbox on" — the registration recorder recommends it in as many
  words for a sign-in through an external provider, and the documentation told you to mount the
  bundled form with it. Mounted that way the form rendered **nothing** wherever the document had
  been published silently.

  It sourced its documents from `Consent::outstanding()`, which filters on the notice mode of a
  version CHANGE. A first acceptance is not a change: nothing was announced because nothing moved,
  so a document first published as a silent editorial version was never in that set — which is
  every privacy notice, and any contract published with `--editorial`. A subject who
  had accepted nothing was shown "everything current, nothing to do", while `statusFor()` reported
  the same keys as owed — the two surfaces disagreeing about one person, each correct for its own
  question.

  `Consent::firstAcceptance()` asks the other one: does this subject hold this at all. That is
  wider than "never accepted" — somebody who withdrew, declined or terminated holds nothing either,
  and is asked again rather than served without an agreement. The form picks its source from the
  method the mount declares, which is `#[Locked]`, so a screen cannot show one question and record
  the other.

  **It covers the privacy notice too**, which the previous surface structurally could not: a
  privacy notice may not be published as an active re-consent, for a correct legal reason, so an
  interstitial built on `outstanding()` collected the contract and never the acknowledgement —
  quietly, with nothing red and a data export that looked complete.

- **`legal-consent.gate.first_use`** (default `false`) — the enforcement middleware also stops a
  subject who owes a first acceptance. Read strictly: anything but a literal `true` leaves it off,
  because a gate that switched itself on for a truthy value would stop every subject of an
  application that never asked for it. Turn it on together with the screen above; without one the
  subject lands on the allowlisted consent route and is told nothing is due.

  If you have **published** the config, the key will not reach your runtime until you add it
  yourself: `gate` is a top-level block and the merge is flat, so your published block wins whole
  and the package default is not applied. It reads as `null`, which leaves the gating off — the
  safe direction, and a silent one. `legal-consent:doctor` names the key in its drift section.

- **`legal-consent:doctor` reports mandatory documents published while first-use gating is off** —
  the state a consumer cannot see for themselves, because nobody is counted and nothing goes red.
  It stays quiet when the gating is on: that is a decision, and a doctor that argues with decisions
  gets skipped on the finding that matters.

## [0.19.0] - 2026-08-27

### Added

- **`Consent::forget()` is documented on the facade**, so static analysis stops failing on the call
  the shipped Boost skill prescribes. A guard now holds the facade's `@method` list against the
  contract by reflection, argument for argument, because that list drifts by omission and nothing
  else notices.

- **`hasAcceptedCurrentLegalMany(array $keys)`** on the `HasLegalConsents` trait — the
  `hasAcceptedCurrentLegal()` question for several documents in one read instead of two queries per
  key.

- **`routes.api_throttle`**, defaulting to `'60,1'`. See the upgrade guide: a config published under
  an earlier version does not receive the key, and the package applies the default anyway.

- **Three exceptions that name what was refused**: `IncompatibleConsentActionException`,
  `LegalDocumentInEvidenceException`, `NoticeTimelineInvertedException`.

- **`legal-consent::ui.not_withdrawable`** in all seven bundled locales.

- **`--isolated` on all three scheduled sweeps**, which now implement `Isolatable`. Until now
  `withoutOverlapping` protected only the scheduler path, so a hand-started prune ran unguarded
  beside a scheduled one.

- **`retired` in the `statusFor()` map and in the settings screen.** Retiring a document does not
  end the consents recorded against it, so the holding stays — at the version the subject accepted,
  with its withdrawal control, flagged, and never as `outstanding`. `ConsentFake` returns the same
  key, which is the only way a consuming test can stay honest about it.

- **`LegalDocumentPublisher::previewWithMode()`** — the publish-time checks with nothing written,
  so `legal-consent:publish --dry-run` runs the rules the real command runs instead of reporting
  green for a publish it would refuse. Both paths call the same extracted guards; there is still
  exactly one source for the legal rules.

- **`SubjectKeyTooLong`**, thrown when a subject's key exceeds the 64 characters `subject_id`
  holds. SQLite truncated it silently while PostgreSQL and MySQL raised a bare SQLSTATE.

### Changed

- **The change-notice proof is written when the notice is DELIVERED, not when it is queued.** See
  the upgrade guide — the observable result of a working run is unchanged, and what changes is what
  a broken one leaves behind.

- **`Consent::record()` refuses an action the document's type cannot carry.** The ledger used to
  accept an objection against a consent, a consent given by silence, a `granted` on a privacy
  notice, and a double-opt-in confirmation with no request before it. The named transitions are
  unaffected.

- **A `legal_documents` row a consent points at can no longer be deleted**, by trigger and by model
  hook. Retiring a version with `is_active = false` is the supported route and still works, and so
  does deleting a version nobody ever accepted.

- **Withdrawal survives retirement.** `withdraw()`, `object()` and `terminate()` fall back to the
  version the subject actually accepted when no active version exists. Deactivating a document used
  to remove it from every screen while leaving the consent in the ledger as held — the subject was
  bound by a record they could no longer act on.

- **Tamper-evidence covers `tenant_id`.** Appended only when it names a tenant, so a single-tenant
  installation produces the same canonical bytes as before and keeps verifying with no action. A
  multi-tenant installation with tamper-evidence on must re-chain; the upgrade guide says how.

- **`legal-consent:publish` refuses an inverted notice timeline** and floors `notice_period_days` at
  zero. A hard-gating change could take effect thirty-one days before its own announcement and
  freeze that as `-31`.

- **`vendor:publish --tag=legal-consent` no longer copies the two opt-in migrations.** The umbrella
  tag ships what the package requires; the optional tables keep their own tags.

- **Terminology is now held per locale rather than per string.** A withdrawal was called one thing
  on the button and another in the confirmation, the imprint page had one name in the footer link
  and another as its heading, and free-of-charge termination was named two ways in Spanish.

- **The MySQL identity columns of `legal_documents` carry a binary collation.** The unique index over
  them decides whether two rows are the same document, and MySQL's default collation is case- and
  accent-insensitive where the other two engines are not.

- **The change-set freeze guard refuses an engine it cannot protect** instead of installing nothing
  and saying nothing — the same choice `ProofColumnGuard` already made, and for the reason written
  there: a migration that stops is recoverable, a table that only looks frozen is not. For
  PostgreSQL, MySQL and SQLite this is a no-op; a fourth engine already failed earlier in the chain.

### Removed

- **`notice_periods.dcd_termination_days`** from the published config. It is the § 327r Abs. 3
  free-termination window — a figure fixed by statute that a notice states, not a period before it —
  and nothing read it. A configuration key that offers to change an unchangeable number is worse
  than no key.

### Fixed

- **A subject with an integer key stopped using the ledger's indexes on MySQL.** 0.18.0 widened
  `subject_id` to `varchar(64)` so a UUID- or ULID-keyed subject fits alongside an auto-increment
  id. The binding did not move with it: a model with an integer key hands `getKey()` back as a PHP
  int, Laravel binds an int as `PDO::PARAM_INT`, and MySQL compares a `varchar` column against a
  numeric operand by casting both sides to floating point — a comparison that cannot use an index
  on that column. Every subject lookup on MySQL became a full scan of the ledger, and `2`, `'02'`
  and `'2.0'` became one value to a comparison that this table treats as three different subjects.

  Both fast engines hide it, which is why nothing went red: SQLite converts the operand through
  column affinity, and the pgsql driver sends parameters untyped so PostgreSQL infers `varchar`
  from the column. The narrowing now lives in one place, and the regression test asserts on the
  BINDING rather than on a query plan, which is the only portable way to hold it.

- **The consent gate read the subject's whole ledger on every request.** `outstandingFor()` runs on
  every authenticated request of an application that enforces re-consent, and it folded the entire
  append-only ledger to answer a question about a handful of enforceable documents — so the
  per-request cost rose with how long someone had been a customer, for rows the fold then
  discarded. It now restricts the read to the keys the answer can turn on.

- **Three shipped WireKit controls sent an uncompiled Blade directive to the browser.** A directive
  inside a component tag attribute is never compiled: the tag compiler lifts the attribute value
  out as a literal before the directive compiler sees it. `wire:click="releaseAll(@js($key))"`
  therefore reached the browser verbatim, for every key, with no console error and no log line.
  One of the three is the Art. 7(3) withdrawal.

- **The plain `consent-checkboxes` stub showed no validation error.** Twenty-one shipped error
  strings were unreachable there, so a failed mandatory consent was silent — to everyone, screen
  reader included. The message is now rendered, announced, and referenced from the field's existing
  `aria-describedby` rather than beside it (two of that attribute on one element means the browser
  keeps the first and discards the second).

- **Both consent banners rendered a dead call to action** when the embedding page passed no consent
  URL, which is the `href="#"` pattern 0.16.1 already removed from the withdraw button. There is
  now no link rather than one that goes nowhere. The same applies to the placeholder edit link in
  the legal-text grid.

- **The withdraw controller flashed an English exception message into a user-facing alert**,
  internal document key and enum value included, in every locale. It now flashes a translated
  sentence and logs the internal reason for the operator, who is the reader who can act on it.

- **The withdraw controller followed the `Referer` header unchecked** while its sibling in the
  re-consent form defends against exactly that. It now returns only to its own origin, and falls
  back to the configured home route otherwise.

- **Three documents showed a Livewire component name the package does not register.** The Boost
  skill, `UPGRADE.md` and the recording-consent guide all used
  `<livewire:legal-consent.re-consent-form />`; the registered alias is
  `legal-consent.reconsent-form`, so a consumer following any of the three got an exception on the
  first render.
- **An interrupted retention sweep left the hash chain broken and never healed it.** The repeat run
  reported success while `legal-consent:verify-ledger` reported tampering for good. The repair is
  state-based now rather than run-based.

- **`legal-consent:close-objection-windows` stamped a window closed for subjects it could not deem**
  for lack of proof, which killed the recovery path its own error message names.

- **`legal-consent:verify-ledger` walked the chain with LIMIT/OFFSET**, so a concurrent append could
  make it see one row twice and report a break that was not there. It is a keyset seek now.

- **`legal_change_sets.document_id` carried the foreign key that migration 000008 removed from
  `legal_consents`** — `ON DELETE SET NULL` against a table that is frozen by trigger. The three
  engines answered it three different ways, one of them by silently mutating a row the package
  promises is immutable.

- **The freeze guard had no SQLite arm at all**, so a published change description was editable and
  deletable there through any path that is not the model.

- **The MySQL proof trigger compared with `<=>`, which is collation-dependent**, so a change of case
  or accent alone on a frozen proof column went through.

- **Every raw statement named its table unprefixed**, so `php artisan migrate` aborted on any
  connection with a table prefix set.

- **The Art. 17 erasure re-linked rows belonging to several subject tokens in one pass**, chaining
  two strangers' ledgers together.

- **The activation lock was taken on a different cache store by the releaser than by the model**,
  under the same name — so the two never actually excluded each other.

- **`LegalDraftWriter::persist()`'s recovery from a concurrent insert was unreachable on
  PostgreSQL.** A failed statement there aborts the whole open transaction, so the read that
  recovers could not run inside one. The insert is confined to a savepoint now.

- **`ReConsentForm::submit()` did not clear the ticked boxes after a successful round**, so a tick
  from an earlier round silently accepted a LATER version of the document.

- **Three shipped WireKit controls sent an uncompiled Blade directive to the browser.** A directive
  inside a component tag attribute is never compiled, so `wire:click="releaseAll(@js($key))"` was
  delivered verbatim for every key, with no console error and no log line. One of the three is the
  Art. 7(3) withdrawal.

- **`ConsentFake` was more forgiving than the real manager under `Model::shouldBeStrict()`**, so a
  green test in a consuming application hid the exact 500 production would produce. It now hydrates
  the same attribute set, and the contract names that set.

- **`ConsentPresenter` selected documents without their primary key**, so the `document_url` seam
  threw a `UrlGenerationException` on two of the three surfaces that use it, and it dropped the
  withdraw button after a major bump although the withdrawal still worked. The registration
  checklist had the same omission.

- **`hasCurrent()` cost two queries per document key with no memo**, which made the public trait
  method an N+1 by design. It now reads the document from the enforceable-document cache and the
  ledger through the gate's key filter — and a contract arm holds it against `outstandingFor()`
  across four states, because the two must never disagree about one subject.

- **The ledger walk's keyset seek had no index to seek on.** Measured on PostgreSQL 18.4 over 100
  chained subjects: with only the single-column index the planner fell back to an incremental sort
  and re-read each subject's chain from the start on every page — cost that scales with one
  person's history, not with the page. The composite `(subject_token, id)` replaces it; on MySQL
  the swap is a no-op, because InnoDB appends the primary key to every secondary index anyway.

- **`legal-consent:prune`'s correlated subquery named its table unprefixed**, so the sweep broke on
  any connection with a table prefix. It is expressed through the query builder now, which
  prefixes the alias and the correlated columns itself.

- **`legal-consent:dispatch-notices --dry-run` hydrated the whole affected population to count it.**
  It counts in the database now — and stops reporting subjects as "already proofed" when the only
  thing wrong with them is that their `subject_type` no longer resolves to a model.

### Security

- **The JSON API's shipped default middleware carried no throttle**, so four unauthenticated write
  endpoints pointed at an append-only ledger with nothing in front of them.

- **Four public Livewire properties were client input steering an unforgeable record.**
  `ConsentSettings::$locale` let the client choose which language version of a document was written
  into the ledger as the proof; `LegalTextEditor::$key` and `$locale` let an editor mounted on one
  draft save, translate and human-approve a different one; `AnnouncesStatus::$status` let the client
  put words into the region a screen reader announces. All are `#[Locked]`, and a reflection ratchet
  now requires every public property to be classified.

- **`ReConsentForm::isSameOrigin()` judged the URL before removing the tab, newline and carriage
  return a browser strips out of a path.** `/<TAB>/evil.example` passed as same-origin and resolves
  to another host once the browser is done with it. The withdraw controller followed the `Referer`
  header with no check at all; it now returns only to its own origin.

- **`LegalTextManager::releaseAll()` trusted its action argument**, so an unknown key reached the
  source factory as an uncaught exception — a 500 on an admin screen where the rest of the package
  answers 404.

- **A partially specified `markdown` config block fell through to CommonMark's own defaults**,
  `html_input=allow` and `allow_unsafe_links=true`, which changes what is rendered into the frozen
  proof text. The package defaults now merge per key.

## [0.18.0] - 2026-08-26

### Changed

- **A subject can now be keyed by a UUID or a ULID, not only by an auto-increment id.**
  `subject_id` on `legal_consents` and `legal_notices` was an integer column, and there was no seam
  an application could bend to fit: no config key, no cast, no overridable attribute. An
  application whose users carry UUID keys — Laravel's own `HasUuids` shape — was answered by
  PostgreSQL with `invalid input syntax for type bigint` and by MySQL with `Data truncated`, on the
  first read of every consent path, so registration, re-consent, withdrawal and notice delivery all
  failed together.

  The column now holds 64 characters, which fits a UUID (36), a ULID (26) and an auto-increment id
  as its own decimal text, with room for a prefixed key. Existing installations are widened by a
  migration; **no stored row is rewritten and no proof needs re-chaining**, because the canonical
  proof form has always hashed the string cast of every field — an id that was a `bigint` and is
  now text produces the identical hash, on PostgreSQL and MySQL alike.

  The rollback is deliberately allowed to fail: once a subject with a UUID key has consented, no
  integer column can hold that proof, and quietly dropping it would destroy the evidence the ledger
  exists to keep.

  The optional v1 backfill changes with it. It used to skip any non-numeric `users.id` — while the
  column was an integer that check was the only thing standing between a UUID and PHP casting it to
  `0`. Keeping it would now silently drop exactly the installations this change is for, leaving a
  green backfill and an empty ledger, so it refuses only values with no lossless string form.

- **`Consent::forget($subject)` — Art. 17 erasure that leaves the proof and the chain intact.**
  The package described this step in two places and made it impossible in a third: clearing the
  subject columns is an `UPDATE`, and both ledgers refuse every `UPDATE`. There was no seam a
  consumer could reach for, so a deleted person's rows stayed for good with the ip address and user
  agent still in them — neither orphaned nor superseded, so the retention sweep never touched them.

  Each row is now deleted and written again without the columns that name the person
  (`subject_type`, `subject_id`, `ip_address`, `user_agent`, `request_id`), at its original id and
  inside one transaction. What proves the consent survives untouched, as does `subject_token` — the
  pseudonym that still ties the two ledgers together. Every rewritten row records
  `subject_erased_at`, because a lawful change to append-only evidence that leaves no trace is
  indistinguishable from the tampering the ledger exists to catch.

  **The chain is re-linked as part of the same operation, and it has to be.** The obvious version —
  rewrite the row carrying its old `prev_record_hash` — was measured and breaks the chain twice
  over: the erased columns are all inputs to the row hash, and a row's hash folds in its own link,
  so one correction changes the next one's input. Without the re-link, every lawful erasure would
  leave `legal-consent:verify-ledger` reporting tampering for good.

  Ids are reused rather than reassigned, which is load-bearing rather than tidy: the verifier flags
  an unchained row whose id is past the first chained row **in the whole table**, so rows
  re-inserted at fresh ids would each land past that watermark and be reported as direct database
  writes.

  The notice ledger is erased in step with a shorter column list — it has no `ip_address`,
  `user_agent` or `request_id`. `notice_body` deliberately **stays**: it looks like per-person data
  and is not, because the dispatch renders that proof once per version and serves the same text to
  everyone. Clearing it would destroy the durable-medium proof that makes a deemed acceptance
  binding and remove nothing about the person.

  `ConsentFake` gains `forget()` plus `assertForgotten()` / `assertNotForgotten()`, so a consuming
  application can prove its delete-account flow made the call without migrating these tables.

- **`legal-consent:prune` re-links the chain it breaks, instead of only reporting the break.**
  Measured before any of this existed: a chain of three verified intact, the sweep removed the two
  superseded rows and exited 0 with its usual success line, and the next `verify-ledger` reported a
  break — permanently, on a ledger nobody had tampered with. The previous release said so out loud;
  this one repairs it, because a lawful removal has to leave a verifiable ledger or the package
  trains its operators to ignore the one alarm it sells.

  A survivor whose predecessors are gone becomes its chain's new start; one whose mid-chain
  predecessor is gone points at whatever now precedes it. Only rows whose link actually moved are
  rewritten, ids are reused, and the repair runs after every chunk of both ledgers — a later chunk
  deleting from the same subject would otherwise break exactly what an earlier repair had fixed.
  The sweep says when it happened, and only then.

  The walk is shared with the Art. 17 erasure rather than written twice: two copies of something
  this subtle drift apart on the first change to either.

### Fixed

- **The bundled WireKit views name the color axis by its canonical name.** Five `alert` and
  `callout` tags set `variant=`, which WireKit carries only as a back-compat alias of `intent=`.
  Both render identically today, so nothing was visibly wrong — but the alias is being retired,
  and once the declaration falls away the prop lands in the attribute bag as inert HTML: the
  re-consent gate's warning callout and the deemed-acceptance objection window would render in the
  neutral default, with no error and nothing in the page to notice.

  Only the components that carry a color meaning change: `card`, `empty-state` and `tabs.list`
  keep `variant` as their *surface* axis and are untouched. If you have published these views, the
  same five lines are worth changing in your copy — a re-publish will bring them over anyway.

- **The bundled registration checkboxes mark the document link's language, and survive a failed
  submit.** Two gaps, both reported by an application that kept its own view rather than adopt
  these — which is the outcome a bundled view exists to prevent.

  The document link now carries `hreflang` from the item's own locale. A mandatory document
  published only in the default locale still binds, so it is shown in the language it exists in —
  the case `RegistrationChecklistItem` describes in its own docblock — and the link then points at
  a text in a different language than the page. Without the attribute neither a screen reader nor a
  translation service is told (WCAG 3.1.2, Language of Parts). It is omitted, not emptied, when an
  item carries no locale.

  The checkbox state is also restored from the visitor's own previous submit. Until now a mistyped
  e-mail wiped every consent already given and the visitor re-ticked the same boxes — which is how
  a consent screen stops being read. **This is not a pre-ticked box**: what Planet49 (C-673/17)
  forbids is a default set by the provider, and `old()` holds only what this visitor sent a moment
  ago. An unticked box is not submitted at all, so it carries no key and comes back empty; a first
  visit has no previous input and renders nothing checked. All three cases are held by tests, in
  both the plain and the WireKit view.

- **Ten shipped files pointed at test classes no consumer receives.** The test tree never ships and
  is `export-ignore`d from the dist, so a comment naming a test class is a pointer that resolves to
  nothing in an installed package — in `src/`, in two migrations, and in the upgrade guide. One of
  them had rotted further and named a class that no longer existed anywhere after a rename.

  Nothing secret was exposed — a reader simply had no way to follow the pointer. Each of those
  sentences now states the fact instead of naming the symbol.

- **`legal-consent:prune` says when a sweep has broken a tamper chain.** Measured: a chain of three
  verified intact, the sweep removed the two superseded rows and exited 0 with its usual success
  line, and the next `legal-consent:verify-ledger` reported a break — permanently, on a ledger
  nobody had tampered with. Nothing connected the two events.

  Removing those rows is correct: `DELETE` is the one mutation the ledger allows and retention is
  not optional. What was wrong is the silence. This command's own documentation calls it "the one
  scheduled task whose SILENCE is the failure", and an operator who cannot tell a lawful sweep from
  an attack stops reading the alarm. The line appears only when a chained row was actually removed,
  so an ordinary nightly sweep is unchanged.

  It does **not** repair the chain. Reconciling lawful removal with an append-only hash chain is a
  design question, not a bug fix: every proof field an erasure would clear is also an input to the
  chain hash, so removing the person and keeping a verifiable chain cannot both be done by clearing
  columns.

- **`legal-consent:publish --all --only-missing` tells a missing file from an unwritten draft.**
  Both raise the same `LegalDocumentNotFound`, so the gap-filler could only answer both the same
  way — and it answered "warn". For a draft that is right: a deploy line cannot make an editor
  write a legal text. For a markdown file that should be in the repository it is exactly wrong —
  nobody is going to write that one, and skipping it produces the empty legal page behind a green
  deploy this command exists to prevent.

  A source now says which it is. `AwaitsAuthoring` is a marker a source carries when its empty
  state means a person has not written yet; the shipped draft source declares it, and anything
  else counts as provisioned and still fails. Reading the configured name instead would have
  worked for the two drivers here and been wrong for the case the package is built around — a
  consumer's own editorial source, told that its normal empty state is a deployment fault.

  `--dry-run` makes the same distinction now, which it did not: it counted every textless source
  as a warning and exited 0, including under the bare `--all` where the run it previews fails. A
  preview that reports green for a run that cannot be green is worse than no preview.

- **Two shipped comments described a state no supported operation can reach.** `SubjectToken` said
  the pseudonym is what survives "after an Art. 17 erasure nulls subject_type/subject_id", and the
  retention sweep said that deleting a subject "orphans the record". Clearing those columns is an
  `UPDATE`, which both ledgers refuse at the model and, on PostgreSQL and MySQL, at a trigger; and
  deleting the subject's own row leaves the columns exactly as they were, so the sweep's `is null`
  arm is never reached that way.

  The consequence is worth stating plainly rather than quietly rewording the comments: a deleted
  person's consent rows are neither orphaned nor superseded — no newer
  row will ever arrive for them — so they are kept for good, with the ip address and user agent
  still in them. Both comments now say what is true today and name the open question instead of
  describing a capability that does not exist.

## [0.17.0] - 2026-08-23

**A minor bump that carries one breaking change**, which SemVer `0.y.z` allows and `UPGRADE.md` has
said from its first line: `ConsentManager` gains `requestConfirmation()` and `confirm()`, so a
custom implementation of the interface no longer satisfies it. Nothing else removes or renames
anything — but `ui.variant` defaults to `auto`, so an application with WireKit installed will *look*
different without having changed a line. Both are written up in `UPGRADE.md`.

### Added

- **Double opt-in is provable from the ledger alone.** `ConsentAction` gains `optin_requested` and
  `confirmed`, `ConsentMethod` gains `double_opt_in`, and the manager gains
  `requestConfirmation()` and `confirm()`. Until now both halves could only be written as
  `granted`, so two rows with different timestamps were all that was left and whoever had to prove
  which one was the confirmation could only assert that the second one was — on the question that
  gets asked most often, because for advertising e-mail the confirmed double opt-in is the German
  benchmark (§ 7 Abs. 2 UWG with Art. 7 DSGVO) and the burden of proof is the controller's.

  **The unconfirmed row is not a consent**, and that is the decision the whole design turns on. It
  does not raise `accepted_major`, `hasCurrent()` stays false, and nothing about it can gate — a
  voluntary consent may never gate at all (Art. 7(4)). The alternative —
  a consent that lapses if unconfirmed — would have the ledger assert, for the whole unconfirmed
  window, a consent that never existed. `statusFor()` gains `pending_confirmation` for that middle
  state, which is otherwise invisible: entered but unconfirmed folds to the same zero as never
  entered, so a screen would invite the subject to enter themselves a second time and supersede
  the link already in their inbox. All four bundled settings views show it instead.

  A request neither grants nor ends anything, so the gate's fold treats it as **neutral**, the way
  it already treats an objection: re-declaring an interest in something already held must not
  silently drop it.

  `ConsentConfirmationRequested` is a separate event on purpose — `ConsentRecorded` is what a
  consuming application provisions on, and letting the request fall through to it would be the
  silent version of the whole problem. Sending the mail stays the application's job; this is the
  seam.

  A confirmation is refused with `NotConfirmableException` when there is no pending request (a
  second click on the same link included), when `double_opt_in.confirm_within` has elapsed, or
  when a new **major** was published in between. The `reason` is a field rather than a phrase in
  the message, because those three need different copy.

- **`registration.without_form_fields` — the no-form warning can now be a refusal.** Since 0.16.0
  the recorder logs when it writes a mandatory consent from a request that carries no
  `legal_<key>` field, which is what an external-provider sign-in looks like: `Registered` fires,
  no form ran, and a row asserting that a human acted is written anyway — into a table nothing can
  correct afterwards.

  `refuse` raises `UnevidencedConsentException` and records **nothing**. The recorder now resolves
  every document before its first write, so a registration keeps all of its consents or none; a
  partial ledger is the one outcome an append-only table cannot recover from.

  The default stays `warn`, and that asymmetry is the decision rather than caution: the check can
  only look for the field name `RegistrationRules` generates, so an application with its own
  registration form, naming its fields differently, is correct and carries no `legal_terms` — under
  a refusing default it would get failed registrations on the one path every current consumer uses.
  An unrecognized value means `warn`, and `legal-consent:doctor` reports it, because a fallback
  that is silent leaves someone who typo'd `refuse` believing they are refusing.

- **A withdrawal route the settings stub can actually post to.** `routes.web` (off by default)
  registers one session-backed endpoint, `POST {web_prefix}/consent/withdraw`, behind `['web',
  'auth']`. It withdraws and redirects **back** with a flashed `legal-consent.status`.

  The stub shipped a withdraw button whose action was `$consent['withdraw_url'] ?? '#'`, and
  `withdraw_url` had **no producer anywhere in the package** — one occurrence in the whole tree,
  that line. So the fallback always won: the button looked like a working control and did nothing,
  under a comment promising Art. 7(3). The stub cannot call a Livewire action (framework-agnostic
  is the point of it), and the JSON API is off by default, has neither session nor CSRF, and
  answers `204` rather than redirecting.

  `ConsentPresenter` now fills `withdraw_url` — only for a withdrawable consent the subject
  actually holds, and only while the route is registered. With it off the stub renders **no**
  withdraw form rather than a dead one. Pointing the key at your own route still works.

  The route is **always allowlisted** by `EnsureLegalConsent`, like `logout`: a subject held at
  the re-consent gate can still withdraw a voluntary consent, because reaching that right only
  after accepting something new is the coupling Art. 7(4) prohibits.

- **`legal-consent.ui.variant` — the package now picks its own view set.** Every screen ships
  twice, plain and WireKit-native, and until now the WireKit set was reachable ONLY by publishing
  `--tag=legal-consent-wirekit`. Nothing said so: an unstyled view renders, so no test goes red, no
  exception is raised and nothing is logged. A WireKit application therefore served raw HTML on its
  consent screens — the re-consent gate among them, the one screen a subject cannot get past — and
  the only way to notice was to look.

  The default `auto` serves the WireKit set when `pushery/wirekit` is installed at
  **≥ 2.26.0**, and the plain set otherwise. `plain` and `wirekit` pin the choice. A view published
  into `resources/views/vendor/legal-consent` still wins over both.

  The version floor is part of the automatic choice rather than an afterthought: a Blade component
  tag compiles unconditionally, so serving views that name a component the installed WireKit does
  not have would replace a silent styling problem with a hard exception — on the gate, at the
  moment a legal change lands. Below the floor the plain set stays, and `legal-consent:doctor`
  now reports that state, along with an unrecognized `variant` value.

- **The consent settings screen has an empty state.** All three views rendered their three
  headings unconditionally, so a group with no entries was a heading over nothing. That is not the
  edge case it looks like: `ConsentPresenter` reads the `legal_documents` table rather than the
  Markdown sources, and a freshly installed package has nothing there until `legal-consent:publish`
  runs — so an all-empty screen is the SHIPPING state, what every consumer meets between
  `composer require` and their first publish.

  A group with no entries now names itself (`contracts_empty`, `acknowledgements_empty`,
  `consents_empty`) and keeps its heading, because the separation of the three legal kinds is the
  point of the screen. When all three are empty the headings give way to one sentence
  (`nothing_published`), since three sentences over nothing say less than one. All four keys ship
  in every one of the seven locales.

### Fixed

- **A failed registration no longer leaves half a ledger behind.** Separating resolution from
  writing (so `refuse` above can be honest) closed a second partial-write path as a side effect:
  `accept()` used to run inside the resolution loop, so a registry whose first key resolved and
  whose second did not left the first key's proof row behind and then failed the registration. The
  application saw an exception, the subject saw an error, and a row stayed in the append-only
  ledger for an account that was never created.

- **The WireKit settings stub withdrew through `wire:click`, on a page with no Livewire component
  behind it.** It is the twin of the *framework-agnostic* stub, not of the Livewire view, and the
  directive had been copied from the latter. On an ordinary page it is inert: the confirmation
  dialog opens, the button is pressed, and nothing at all happens — no error, no log entry, no
  failing test. It now posts a real form, submitted from inside the alert-dialog by the button's
  `form` attribute, so the irreversible action keeps its confirmation.

- **The registration checkbox stubs now take the input name from the checklist item instead of
  building it themselves.** `RegistrationChecklistItem::field()` exists so a consumer does not have
  to guess the convention: a document control is `legal_{key}`, and an ATTESTATION — today the
  Art. 8 age gate, which is about the person and has no document — is named by its key alone. Both
  shipped views built `legal_{key}` in the template, so with the age gate on the form rendered a
  required `legal_age_confirmed` while `RegistrationRules` demanded `age_confirmed`. The visitor
  could tick the box and never complete the registration, and the error pointed at a field that
  was not on the page.

  Both views now read `field` from the item. A hand-built `$documents` list in the documented
  minimal shape still works unchanged — that shape lists documents only, and a document *is*
  `legal_{key}`.

- **The WireKit checkbox variant renders the accept-time guard field, like the plain stub already
  did.** Publishing the themed variant silently dropped the opt-in `{field}_hash` input, so a
  consumer who themed the form got the path without the guard: a version released between page
  load and submit was frozen unseen instead of raising a 409.

  Both halves are now bound to `RegistrationRules::required()` in both directions — no rule without
  a control, no control without a rule.

## [0.16.1] - 2026-08-21

**Two fixes, both in places where nothing went red.** A consumer who declines this package's tables
with `ignoreMigrations()` was still getting three scheduled commands a night against relations that
do not exist; and the two publishable settings stubs never rendered the document link the presenter
has carried since 0.13.0, so a screen built from a stub left the title as plain text.

Nothing here is breaking, and no configuration changes.

### Fixed

- **The two publishable settings stubs now link the document, like the component views already
  did.** `ConsentPresenter::settingsFor()` has carried a `url` key since 0.13.0, and both Livewire
  views render it — but the stubs a consumer publishes and styles did not, and their header
  comments documented a data contract short of the key, so nobody reading them would have looked
  for it.

  Where the host configured `legal-consent.document_url` the title is now a link in both stubs;
  where it is not, it stays plain text with no empty `href`. A settings screen on which the
  document being withdrawn cannot be read is silent exactly where Art. 7(3) assumes the subject
  knows what they are deciding about.

  Both stub headers now state all seven keys, that `outstanding` is always false for a consent
  (demanding a voluntary one would be Art. 7(4)) — and, in the framework-agnostic stub, that its
  withdraw form's `withdraw_url` is **not** supplied by the package and must be pointed at your own
  route. As shipped that button submitted to `#`.

- **A consumer that declines the package's tables no longer gets three failing scheduled commands a
  night.** `ignoreMigrations()` and the three `schedule.*` config flags answered different
  questions and nothing connected them: the config flags say whether you WANT a sweep, while
  `$runsMigrations` says whether it CAN run here at all. The flag was read in exactly one place —
  where the migrations are loaded — so `dispatch-notices`, `close-objection-windows` and `prune`
  were registered regardless, then ran every night against relations that do not exist. With
  schedule monitoring that is one tracker entry per run, indefinitely.

  For this package it is worse than noise: `dispatch-notices` sends overdue re-consent notices, and
  their absence is legally relevant. A sweep that is permanently red is where a real failure stops
  being visible.

  The registration is now gated on `self::$runsMigrations`, read at boot rather than inside the
  closure, so nothing is registered that could only fail later. No database access is added at boot,
  and an installation that keeps the tables is unaffected.

## [0.16.0] - 2026-08-20

**The settings screen now distinguishes "you never accepted this" from "a new version is waiting
for you"** — the two positions that looked identical on the one screen where the subject could
still act voluntarily, before the gate compels them. Around that: the registration listener says
when it records a consent no form ever validated, the tamper-evidence chain refuses a value it
cannot hash instead of folding it onto the empty string, and the README badge row follows the
fleet canon.

Nothing here changes an existing hash or an existing consent row. One behavior changes for code
that hands the chain an Eloquent model instead of a database row — see `UPGRADE.md`.

### Added

- **The settings screen now says which rows are asking for something.** A row the subject has not
  accepted looked identical whether nothing was pending or a new major version was waiting for
  them — and only the second one ends at a gate that compels. The screen where it could have been
  done voluntarily was the one that did not say so.

  Each row of `ConsentPresenter::settingsFor()` carries `outstanding`, computed exactly as
  `Consent::statusFor()` computes it, so the screen and the status map cannot disagree. A
  voluntary consent is never outstanding however long it goes ungiven — demanding one would be
  Art. 7(4). All four shipped views render it as *Action required*, translated in every locale.

  Additive: a new key breaks no consumer that does not read it.

- **The registration listener now says when it records a consent no form ever validated.** Way B
  fires on the standard `Registered` event. A contract or an acknowledgement is accepted there
  unconditionally — correct while a form ran, because `RegistrationRules` made the box required and
  validation already happened. Sign people in through an external provider and no form runs, so the
  row whose entire purpose is to prove a human acted gets written without one having.

  Recording a mandatory document whose `legal_<key>` field is **absent from the request** now logs a
  warning naming the keys, the subject type and the method, plus what to do instead.

  That is an observation about the request rather than a guess about your application: the input
  either carried the field or it did not. Asking the router whether a registration form exists
  cannot be made reliable, because an application may name that route anything.

  It warns and still records. Refusing would break every application whose form names the fields
  differently — that is a decision about your data, so the package reports and leaves it to you.

### Documentation

- **The README badge row follows the fleet canon: identity first, all of it read from Packagist,
  then what the gate enforces.** The license badge already resolved from Packagist rather than
  being hardcoded — the drift that started the fleet-wide review — but the row was single-line and
  carried no test, coverage or type-coverage badge at all, and the license sat in the middle of the
  quality badges instead of closing the identity row.

  Added: Pest 5, coverage 100%, type coverage 100%, and *tested on PostgreSQL + MySQL*. No Livewire
  badge — it is a `require-dev` dependency here, and badging it would tell a reader that installing
  this package pulls Livewire into their application.

  **No mutation badge, deliberately.** A static badge may state exactly what the gate enforces and
  no more; the enforced floor is `--min=67` over Unit and Feature only. Next to two 100% badges that
  reads worse than the package is, and raising the floor is real work with a real proof behind it
  rather than a number edited into a README.

  `ReadmeBadgeClaimTest` now holds the one thing that can silently stop being true: a percentage on
  the page against the `--min=` the composer script enforces.

### Fixed

- **The tamper-evidence chain no longer folds a value it cannot hash onto the empty string.**
  `LedgerHashChain` serializes each proof field as `N` for null, else `S<byte-length>:<value>`, and
  the class promised that two distinct rows always produce distinct canonical strings. That held
  only for values with a lossless string form. An array, an object and a bool `false` all cast to
  `''` — which is itself a legitimate value of `source` — so four different rows shared one hash.

  The point where this becomes concrete is an **Eloquent model**. `hashRow()` accepts any object,
  and `LegalConsent` casts `document_type`, `action`, `method` and `accepted_at` to enums and a
  date object. Hashing a model therefore left four of the eighteen proof fields empty — including
  which document, which act and when — producing a link the verifier, reading the same row raw,
  can never reproduce. `legal-consent:verify-ledger` would later report tampering on rows nobody
  touched.

  Such a value is now refused with `UnhashableProofFieldException` naming the field and the type.
  Encoding it distinctly was the other option and is the worse half: it keeps the mistake silent
  and still hashes the model differently from the row.

  **No existing hash changes, and that is the acceptance criterion rather than a hope.** Null,
  string, int and float encode byte-for-byte as they always did, pinned against hashes measured
  before the change. Both shipped call sites pass raw database rows, so nothing shipped was ever
  affected — what was missing is the guard for the obvious override, since `latestChainedRow()`
  is `protected` and an application returning a model from it would break every chain in its own
  system, silently.

## [0.15.0] - 2026-08-20

**An application that signs people in through an external provider can now record consent
truthfully.** Until this release the method enum had no case for the place a first acceptance
actually happens without a registration form, so the ledger had to assert either a form that does
not exist or a re-consent that never happened. Around that: the settings screen can finally give a
consent back, not only take it away; the bulk publish gained the gap-filler a deploy line should
use; and your own tests get a real double.

Nothing here is breaking. The manifest now names `laravel/framework` instead of twelve
`illuminate/*` splits, which resolves to the identical dependency graph — see below.

### Added

- **`ConsentMethod::FirstUseGate` — the capture point for an application with no registration
  form.** Sign-in through an external provider leaves nowhere to put a checkbox, so the first
  acceptance happens in an interstitial shown after authentication and before first use. The enum
  had no case for it.

  That was not a gap but a **false statement**, in the one artifact whose entire purpose is to be
  true. The two available answers were `registration_checkbox`, which asserts a form that does not
  exist, and `re_consent_gate`, which asserts an acceptance *after a document changed* that never
  happened. Under an Art. 15 request the second one renders as a re-consent nobody was ever asked
  for — and the ledger is append-only by design, so it cannot be corrected afterwards.

  **It names the moment, not the mechanism.** OAuth, SSO, an invitation link and a magic link all
  capture at the same point, so one case covers all of them; a case called `oauth_consent` would
  have needed a sibling on the next sign-in route, and every sibling is permanent once it is in a
  stored column.

  `ReConsentForm` already accepted a `method` at mount, so no new seam was needed — pass
  `ConsentMethod::FirstUseGate` and the ledger records the truth.

- **The first-use gate honors `routes.return_to_intended`, exactly like the re-consent gate.** Both
  interrupt a navigation: the subject was going somewhere and was stopped on the way. Restricting
  the return to one of them would have silently dropped the intended destination for every
  application without a registration form. A settings embed is deliberately excluded and does not
  redirect at all — nobody was on their way anywhere when they opened their own settings page.

- **`ConsentSettings` can now GIVE a voluntary consent, not only take one back.** The screen offered
  withdrawal, objection and termination — and no way to grant. A subject who withdrew, or who never
  ticked the box at registration, had no way back; an application with no registration form had no
  way to give one at all. A withdrawal screen with no counterpart is a screen without its subject.

  ```blade
  <livewire:legal-consent.consent-settings :allow-grant="true" />
  ```

  **It is off by default, unlike `allowObjection` and `allowTermination`, and that asymmetry is the
  decision.** Granting is the only direction here that WRITES an assertion that the subject agreed;
  the other three remove or contest one. Every public method of an embedded Livewire component is a
  reachable endpoint whether or not the template renders a control — so switching this on by default
  would have added an endpoint that creates a proof row saying "they agreed" to every existing
  installation, without anyone asking. For a package whose entire product is proof, that is asked
  for rather than assumed.

  **It refuses anything that is not a voluntary consent**, and the refusal is the point rather than
  a safety net. A contract or an acknowledgement is accepted where its full text is presented — a
  registration form, the re-consent gate, a first-use interstitial — because the acceptance has to
  be informed (Art. 7(1)), and a toggle beside a title is not a presentation of a contract. The new
  `NotGrantableException` carries that reason and answers 404 like its siblings.

  Both shipped views render the control opposite the withdraw button, on the same row and mutually
  exclusive with it. The WireKit variant deliberately does **not** put it behind a confirmation
  dialog: giving is reversible in one click on that very screen, so a confirmation there would put
  friction on the harmless direction and none on the irreversible one.

- **`legal-consent:publish --all --only-missing` — the gap-filler for a deploy script.** It
  publishes only the combinations that have no active version and never reads the source of one
  that does, so it cannot classify a change at all.

  `--all` is idempotent for *unchanged* sources, and that is what made it safe to put in a deploy
  line. Once a source has drifted the second run is no longer a no-op — it is a publication, and it
  carries whatever mode stands on that line. In practice that is `--editorial`, because the first
  run legitimately is editorial. So an edited legal text would be filed as the one classification
  that notifies nobody, chosen by a script rather than by a person.

  Measured, because the hazard is narrower than it first looks: editing the text *without* bumping
  its version is already refused (`already exists with different content — bump the version before
  publishing`). It is the author who edits and bumps — which is what an author does when changing a
  legal text — who reaches the silent path. Both halves have a test.

  Two behaviors follow from the same idea. A source with **no text yet** is named and skipped, and
  the run stays green — under the bare `--all` it is still a failure. A gap-filler runs from a
  deploy, where an unwritten legal text is the normal state of an installation whose editors have
  not written it, not something anyone at that console can fix. It is *named*, never silent: a count
  alone would let a document sit unpublished for months behind a green deploy. An unexpected source
  error stays a failure in both modes — "no text yet" is a state, a broken driver is a defect.

- **`legal-consent:publish --dry-run`** on a single document and on `--all`. It resolves every
  source and reports what a run would do, writing nothing: which combination would be published as
  a first version, which would replace an active version because the source has drifted, which has
  no text, and which cannot be read at all. Resolving rather than counting is the point — a version
  that only counted combinations would report the same number for a drifted document and an
  unchanged one, and a missing Markdown file would surface during the deploy that needed it instead
  of in a command someone ran on purpose.

  The resolution runs through `LegalDocumentPublisher::preview()`, so a dry run reads exactly the
  source the real publish would. A caller resolving its own source factory could answer from
  somewhere else, and a dry run whose answer comes from elsewhere is worse than none.

- **`Consent::fake()` — an in-memory test double for your application's tests.** Nothing it does
  touches a database, so you can test your consent screens, your gate and your register form
  without migrating this package's tables into your test schema.

  It exists because this package's own suite could never notice the gap. Every test in here runs
  against a real schema, so the eleven-method `ConsentManager` interface is always satisfied by the
  real manager — while a consuming app had to either migrate the tables or hand-roll a stub of all
  eleven methods, and a hand-rolled stub silently rots the next time a method is added here.

  ```php
  $fake = Consent::fake();

  $this->post('/register', [...]);

  $fake->assertAccepted($user, 'terms');
  ```

  **The read defaults describe a fully-consented subject** — nothing outstanding, `hasCurrent()`
  true, empty status and history, nothing published. That direction is deliberate: a test about a
  checkout or a profile update must not start failing because a consent gate it never mentioned
  decided the subject owes a document. Declare what you care about with `owes()`, `publishes()`,
  `checklistIs()`, `statusIs()` or `historyIs()`.

  **Writes are recorded, never performed.** `record()`, `accept()`, `withdraw()`, `object()` and
  `terminate()` return an *unsaved* `LegalConsent` carrying the attributes they were called with, so
  calling code that reads the returned model keeps working while `$model->exists` stays `false` —
  the honest answer, since nothing was written. Code that persists or reloads it fails loudly rather
  than asserting against a row that never existed.

  Assertions: `assertRecorded()`, `assertNotRecorded()`, `assertAccepted()`, `assertWithdrawn()`,
  `assertNothingRecorded()`, `assertRecordedCount()`, and `recorded()` for anything else. A failure
  names what *was* recorded instead of leaving you to go and look.

  `assertAccepted()` matches `granted`, `acknowledged` **and** `re_accepted`, because which of the
  three the real manager writes depends on the document's type and on history — neither of which a
  consuming test has any reason to know. It deliberately does **not** match `deemed_accepted`:
  silence counting as acceptance is the § 308 Nr. 5 legal fiction, not an act of the subject, and
  "the user accepted" must never be satisfied by the user having said nothing.

### Changed

- **The `registration.listen_to_registered_event` default now says what it depends on.** No
  behavior changed; the shipped config block and the documentation now name the assumption that
  makes it safe.

  It is on by default, and that is correct **because a registration form validated the tick before
  the event fired**. The recorder checks the submitted field for a *consent* document and skips the
  key when it is absent — but for a *contract* or an *acknowledgement* it does not. Those are
  mandatory, `RegistrationRules` makes them required, and re-checking would be a second truth about
  the same thing, so the recorder accepts them unconditionally and relies on the form.

  Sign people in through an external provider and there is no form. The callback carries no such
  fields, nothing validated the tick, and nothing notices: the first callback writes an acceptance
  row for every mandatory document **without a human having done anything** — in the one table
  whose entire purpose is to prove that a human did.

  A default whose safety depends on the sign-in route should say so. Now it does, in both places a
  reader would look.

- **The composer manifest now requires `laravel/framework` instead of twelve `illuminate/*`
  split packages.** Nothing about how you install or use the package changes — every Laravel
  application already has the framework — but the manifest now states what was true all along.

  The old manifest promised a framework-free install and did not keep it. Shipped code calls
  fifteen helpers that only `Illuminate\Foundation\helpers.php` defines — `config()`, `app()`,
  `trans()`, `__()`, `view()`, `request()`, `route()`, `auth()`, `event()`, `url()`, `now()`,
  `response()`, `session()`, `abort_unless()` and `redirect()` — across 185 call sites (measured 2026-08-19), and no
  split package provides a single one of them. An install without the framework resolved fine and
  then fatalled at the first of those calls. Nobody saw it because `orchestra/testbench` pulls the
  whole framework into the vendor tree, so locally and in CI every helper exists.

  Rewriting all 185 sites was the alternative, and it was rejected: it replaces the idiomatic
  Laravel form with injected `Repository`/`Translator`/`Dispatcher` instances, and for `view()`,
  `response()`, `redirect()`, `route()`, `url()`, `session()`, `auth()` and `request()` there is
  no replacement that does not declare the corresponding component anyway. This package is for
  Laravel applications by construction — Eloquent models, migrations, HTTP routes, middleware,
  Blade views, Livewire components — so the manifest was made to match the code.

  `laravel/framework` `replace`s every `illuminate/*` split at the same version, so the resolved
  dependency graph is identical and no lock file moves.

### Removed

- **`LeanDependencyContractTest` and `DeclaredDependencyContractTest` are gone**, replaced by
  `FrameworkDependencyContractTest`. Both existed to hold a promise the package no longer makes.
  The replacement keeps the half that still earns its place: every `Illuminate\…` class shipped
  code imports must resolve to a file inside `laravel/framework`, so a class arriving from some
  other vendor — which would resolve locally and fatal on a consumer's install — is still caught.

## [0.14.0] - 2026-08-18

**MariaDB was supported for one release and is refused again.** That withdrawal is the breaking
change here — read `UPGRADE.md` before upgrading if you installed 0.13.0 on it. Everything else is
a report that fires before a deploy does.

### Added

- **`legal-consent:doctor` now reports a config value that `php artisan config:cache` cannot
  serialize.** Two keys accept a closure — `gate.subject_filter` and `document_url` — and a closure
  in either one makes `config:cache` abort the whole cache with a `LogicException`.

  The failure was distributed in the worst possible way: locally nothing caches, so it runs; the
  package's own suite passes closures on purpose, so it runs; the first sight of it is a deploy,
  after the merge and after a green pipeline. Measured on a real installation, where the deploy
  stopped on `legal-consent.gate.subject_filter`.

  `doctor` now names the offending keys and points at the invokable class-string form, which both
  keys resolve from the container. It is a report, never a failure — a closure is entirely valid
  until someone caches.

- **Both config blocks now lead with the cacheable form.** The class-string is the copyable
  example and the closure is the footnote, which is the way round they should always have been:
  a reader copies the example, not the warning under it.

- **`AffectedSubjectResolver::chunkSize()`** — the audience page size is now a protected seam
  instead of a private constant, alongside the `keysetSeekDriver()` seam already there. Production
  behavior is unchanged: it still returns 500.

  It exists so the paging can be proven where it actually runs. The resolver picks its keyset-seek
  shape per engine — PostgreSQL and SQLite take the sargable ROW-VALUE tuple, every other driver
  the portable OR/tie-break form — but the page boundary was only ever crossed on SQLite, once with
  the driver name forced through the other seam. That proves the SQL shape and nothing about the
  engine it was written for: whether a seek agrees with its sort is a question only a real server
  answers, because both sides read the same collation. The PostgreSQL, MySQL and MariaDB suites now
  each cross the boundary on the real server, in both the gating (`GROUP BY … HAVING`) and the
  info-only query shape, over a fixture whose ids deliberately run against the sort order.

  Reaching that boundary at the production value costs 501 rows per case on three servers, which is
  why those tests did not exist. With the seam it costs six.

### Removed

- **⚠️ MariaDB is no longer supported.** 0.13.0 added it; this withdraws it, and that is a breaking
  change for anyone who installed 0.13.0 on that engine — see `UPGRADE.md` before upgrading.

  It should not have shipped. This package proves itself against the engines it targets — SQLite,
  PostgreSQL and MySQL 8.4 LTS — by re-running its whole database suite against real servers, and
  MariaDB was never in that set. It entered as the repair of a side effect rather than as a
  decision: 0.10.0's proof-column guard named the drivers it could protect, MariaDB fell outside
  because Laravel carries `mariadb` as its own driver name, and the route taken was to support the
  engine instead of to keep refusing it.

  `ProofColumnGuard::assertSupportedEngine()` refuses the driver again, so a fresh install stops at
  the proof-column migration with a named exception. The engine-specific install arms are gone —
  proof columns, the append-only triggers on `legal_consents` and `legal_notices`, and the
  change-set freeze guard. The dedicated MariaDB test suite and its `composer test:mariadb` script
  are gone with them.

  **The DROP paths still name `mariadb`, deliberately.** 0.13.0 did install those triggers, and a
  database carrying them has to be able to shed them; a `DROP TRIGGER IF EXISTS` that matches
  nothing costs nothing elsewhere.

  **MariaDB is still named where it matters — as MySQL's impostor.** It reports e.g.
  `11.4.4-MariaDB`, which clears an 8.4 floor numerically, so the harness and the CI-lane pin keep
  asserting engine identity from the server's own banner. Pointing the MySQL suite at a MariaDB
  server stays a hard failure rather than a silent pass.

## [0.13.0] - 2026-08-17

### Added

- **`notice_mail`: the change notices become something a developer can activate.** There were five
  coarse switches and no seam at all for the declaring person, the template, the sender or the
  subject. Everything below is new, and everything except the shell is **inert until configured** —
  the notice body is hashed into an append-only proof row, so a seam that changed the mail unasked
  would move bytes nobody can correct afterwards.

  - **The package's own Markdown shell, and it is the one new default.** Until now a German § 126b
    declaration went out inside Laravel's global notification template, greeted with "Hello!",
    closed with "Regards," and explaining what to do "if you're having trouble clicking" — all
    resolved from the **consuming** application's translations, so a notice in one language arrived
    wrapped in another. `notice_mail.view => null` opts back out. It changes no byte of the proof
    body, because `->markdown()` sets the template and never touches the mail's lines.
  - **`identity`: who is declaring.** § 126b BGB wants "eine lesbare Erklärung, in der die Person
    des Erklärenden genannt ist", and the package named nobody — the declarant was whatever
    `config('app.name')` happened to be in a footer. Name yours and it is appended to the notice
    **and therefore to the proof row**, which is the point: a declaration that lived only in the
    template would be absent from the very row that exists to prove it. In a multi-tenant
    application bind `ResolvesNoticeIdentity` instead of using the static block — one global
    declarant names the wrong legal person in every tenant but one, and a wrong declarant is worse
    than none.
  - **`from`, `reply_to`, `subject_prefix`, `subject_effective_date`**, plus a package theme
    publishable under the new `legal-consent-mail` tag. The two subject switches are off by
    default because the subject is the first line of the hashed body.
  - **`notification.{mode}`** swaps a mode's notification for your own subclass. The three shipped
    notifications lost `final` and now extend a shared, non-final `ChangeNotification` with exactly
    three members a subclass must supply. A configured value that is not a `ChangeNotification` is
    ignored in favor of the shipped class instead of taking down a queued sweep with a `TypeError`
    after some subjects were already notified and their proof rows written.
  - **`shouldSend()` runs in the worker** — the only place a subject who agreed between the sweep
    and delivery can still be spared. It asks whether they hold this version's major, not whether
    the document is outstanding *now*: a re-consent notice is usually the advance announcement of a
    change that is not yet in force, so the second question would suppress every scheduled notice.
    Only the gating mode is skipped; an info-only or deemed-consent notice is owed regardless.
  - **`NoticeDispatching` and `NoticeDispatched`.** The package had no observability on the notice
    path at all: a sweep that mailed a hundred thousand people and one that mailed nobody looked
    identical from outside. `NoticeDispatching` carries the audience size and a `$cancel` flag that
    holds a version back **without stamping its watermark**, so the notice stays owed — it defers,
    it never waives.

- **MariaDB is supported again — measured on a real server, not waved through.** 0.10.0 made
  `ProofColumnGuard` refuse every engine outside `pgsql`/`mysql`/`sqlite`, and MariaDB fell outside
  it because Laravel carries `mariadb` as its own driver name: a MariaDB connection is never seen as
  `mysql`. An audit recommended simply adding it to the allowlist, on the good argument that the
  MySQL trigger SQL is literally valid MariaDB — but that argument was reached by reading, and
  `ProofColumnGuard` is the guard the package's evidentiary weight rests on. A green run with no
  MariaDB server is indistinguishable from a green run with a broken trigger.

  So the measurement came first: a dedicated MariaDB suite re-runs the whole cross-engine set
  against a real server (`MARIADB_TEST_*`, floor 11.4 LTS), the identity check now recognizes MariaDB
  **positively** rather than only rejecting it as a MySQL impostor, and only then was the allowlist
  opened.

  **Opening the proof guard alone would not have been enough, and that is the finding the suite
  paid for.** Three more trigger families branched on `mysql` only — the append-only triggers on
  `legal_consents` and `legal_notices`, and the change-set freeze guard. A MariaDB installation
  would have passed the guard and received **none** of them: migrations green, immutability silently
  absent. Every one of those branches now names `mariadb`.

- **`legal-consent:publish --all`: the first publish a fresh installation never had.** After
  `migrate`, `legal_documents` is empty. `Consent::published()` returns `null` for every document,
  and the read path deliberately does not fall back to your source files — so every legal page
  built on it renders **empty**, with no error, no log and no warning. The install looked finished,
  the configuration was correct, the Markdown was sitting there, and the pages were shells. Of
  eleven commands, none could publish more than one key in one locale, and there was no seeder and
  no install step.

  `--all --editorial` freezes the whole configured matrix — every registered document in every
  configured locale. It is **idempotent**: text that is already the active version is returned
  untouched, so it belongs in a deploy script. That is where it matters most, because an
  application that gets cloned starts every CI database and every fresh staging box in the empty
  state, and the failure only shows when someone opens a legal page.

  A registered document with no source is **reported and exits non-zero**, never skipped — silently
  skipping it recreates the empty page the command exists to prevent. The run continues past it, so
  a registry of ten publishes the nine that work and names the one that does not.

- **`legal-consent:doctor` names the same gap at any time.** It now reports every registered
  document with no published version, and says which command fixes it. Exit code **0**: this is the
  state every fresh database is in, and a gate step that goes red there gets switched off — taking
  the genuine lost-key findings with it. The exit-code contract is now written down: non-zero for a
  lost key and for a contradictory configuration, zero for a stale key and an unpublished document.

- **`:allow-objection` and `:allow-termination` on the re-consent form.** 0.10.0 gave the settings
  screen these two flags because every public method of an embedded Livewire component is a
  reachable endpoint — deleting a control from a published view switches nothing off. The
  re-consent form carries the same public `object()` and `terminate()` and had no such switch, so a
  product with no answer to objection or termination could disable them on one screen and not on
  the other, and the documentation could only offer "then do not embed it, build your own form".

  Both default to `true`, both are `#[Locked]` (a switch the browser can flip back is not a
  switch), and a disabled action answers `404` rather than `403` — "you may not" confirms it
  exists. This is a product decision, not a security boundary: the manager already refuses a
  transition the document's class cannot carry, and that is unchanged.

- **`ask_at_registration`: a document can bind without being asked at sign-up.** Every registered
  document that asks something appears on the registration form, and there was no way to say
  otherwise. A host that acknowledges a document later, in-app, had to filter the rules, the
  messages and the checklist by hand — and hope the recorder agreed, which it did not: a mandatory
  document is recorded regardless of what the form submitted, so the ledger got proof of an
  acceptance nobody was asked for.

  Set `'ask_at_registration' => false` on a registry entry and the rules, the checklist and the
  recorder drop it **together**. Nothing else changes: a mandatory document still gates, so the
  subject meets it at the re-consent screen instead. The flag moves *when* it is asked, never
  *whether*.

  **It is not the right tool for an Impressum or a cookie policy** — those want
  `legal_basis => 'informational'`, which takes the page out of the gate and the notice sweeps as
  well. Classifying a publication duty as an `acknowledgement` produces a record asserting a
  consent that does not exist in law, and the flag would only hide half of that.

- **`document_url`: one seam that tells every consent surface where the document is readable.** The
  package stores and freezes legal texts but does not own the pages that render them, so until now
  each surface showed a title as dead text and left the link to the host. That was not cosmetic —
  it was the reason the shipped UI could not be adopted whole. Configure a resolver
  `fn (LegalDocument $document): ?string` (or an invokable class-string, which survives
  `config:cache`) and **all three** surfaces link the full text:

  - **The registration checkboxes.** The shipped stubs have documented a `url` key since they were
    written, and nothing ever filled it — a form built from `->toArray()` rendered an empty `href`.
    `RegistrationChecklistItem` now carries `url`.
  - **"Your consents".** `ConsentPresenter::settingsFor()` adds `url` per row. Deciding to withdraw
    a consent without being able to re-read what was consented to is what Art. 7(3) does not want.
  - **The re-consent gate**, where it weighs most: there the subject cannot continue until they
    agree, and the view rendered the acceptance sentence as a plain label with nothing to open
    (Art. 7(2), recital 42).

  The URL is resolved from the **document**, so the link carries that item's locale rather than the
  page's — the distinction the checklist docblock has always insisted on, now made by the package
  instead of by every consumer. Left unset, everything renders exactly as before; a misconfigured
  resolver yields no link rather than a broken one, because an empty `href` navigates to the current
  page and reads as "the document is here".

  It is a **top-level** config key. `mergeConfigFrom()` merges one level deep, so a key added inside
  an already-published block is absent at runtime for every installation that published the file.

### Changed

- **The example `impressum` document key is now `imprint`.** It was the only German key in an
  otherwise English catalog, and `lang/*/titles.php` had already headed that page `imprint` — so the
  shipped config and the shipped translations disagreed with each other. A consumer whose directory
  is called `imprint` had to write a permanent exemption into any check comparing their config
  against the package default, with "it is called something else here" as the reason, and an
  exemption whose reason is a naming collision reads like backlog forever. Two consumers had already
  written that exemption, with two different and equally inaccurate explanations.

  The registry is app-owned and the merge is flat, so an installation that published the config is
  unaffected. See UPGRADE.md for the one group this touches.

- **`league/commonmark` now requires `^2.9` instead of `^2.7`.** Six advisories — four of them
  HIGH — are patched in 2.9.0, and several are squarely in this package's threat model: a
  quadratic-time denial of service on crafted Markdown, and a `DisallowedRawHtml` bypass. The old
  range named 2.7.0 as an acceptable floor while every one of those was open in it.

  Stated plainly, because the honest version is less alarming than the change looks: **nobody was
  exposed through a default install.** Composer refuses to load an advisory-affected version at
  all (`audit.block-insecure`, on by default), so `^2.7` already resolved to 2.9.0 in practice —
  verified by resolving the old range at its floor, which returned 2.9.0 and named the eight
  advisories it had skipped. This closes the gap between what the package *declares* and what the
  tooling *enforces*, for the consumer who turns that setting off or installs with an older
  Composer. No API changes, and no action needed for anyone already on 2.9 or 2.10.

- **Draft pull requests no longer start the CI gate.** A draft is unfinished work by definition,
  and it still pushes; gating it spends one of the queue's two slots on a verdict nobody is
  waiting for. The pull-request arm of the gate now excludes drafts. Ready pull requests gate
  exactly as before, and so do pushes to the integration branches and manual runs. Marking a draft
  ready for review arrives as a different event, so the gate returns on the next push to that
  pull request.

### Fixed

- **An info-only change had no advance-notice check at all, and four `notice_periods` keys were
  read by nothing.** The publish-time branch tested `$mode->gates()`, which is true for active
  re-consent only — so an info-only change hit neither branch, while `NoticeMode`'s own
  documentation assigns P2B, DSA and EECC to precisely that mode. A P2B change published with three
  days' notice went through without a word, and Art. 3(3) of Reg. (EU) 2019/1150 makes a change
  implemented on too short a standstill **void**: it looked shipped and was not in force.

  Underneath it, `minLeadDays()` branched on `psd2_675g` alone. `dcd_termination_days`,
  `p2b_standstill_days`, `eecc_min_days` and `privacy_advance_days` sat in the published config with
  no reader, so an operator who raised the standstill because their contract demanded it changed
  nothing — and nothing said so. The irony was measurable: the guard that refuses an *unknown*
  regime justifies itself by saying it would "silently fall back to a tunable default instead of its
  statutory notice period", which is what four **known** regimes were doing.

  Every regime now resolves its own period, and mode and regime are combined with `max()` rather
  than chosen between — a deemed-consent change under P2B owes both the § 308 Nr. 5 benchmark and
  the Art. 3 standstill, and letting the regime win would have cut a 60-day window to 15. Statutory
  floors (`psd2_675g` 60, `p2b` 15, `eecc` 30) may be lengthened and never shortened, by config or
  by a per-document override; `gdpr` has no floor, because WP260's "well in advance" is guidance
  rather than a number, so it stays tunable in both directions. An unregulated info-only change is
  unaffected.

  `dcd_termination_days` is deliberately **not** mapped: it is the § 327r Abs. 3 free-termination
  window, which runs from the later of notice and modification. It is a figure the notice states,
  not a period before it, and reading it as a lead time would assert a statutory rule that does not
  exist.

  Two guards come with it. Declaring a regime on a **silent editorial** change is now refused —
  saying "this change is regulated" and "no notice is owed" at once left the regime's period applied
  to nothing. And the regime→period map is total over the known regimes, held by a test, so a regime
  added later cannot arrive without someone deciding where its period comes from; `null` is a valid
  answer, but it has to be written down.

- **`legal-consent:doctor` reported a value as lost that was reaching the runtime.** It walked into
  a top-level **list** and compared it by index. A host that deliberately carries fewer locales than
  the package default — `['en']` against `['de', 'en']` — was told that `locales.1  (package
  default: en)` "NEVER reaches your runtime config", about a value sitting at index 0 and working
  perfectly. For a list the index is order, not identity, and its length is the operator's decision.

  Because the doctor is meant as a gate step, this was a permanently red lane with two exits and
  both were wrong: adopt a locale you do not publish (which means publishing a second binding legal
  text), or delete the step and lose the genuine findings with it.

  Lists are now compared as **sets**, reported separately and never as a failure, naming the honest
  fact instead — which member of the default you do not carry. Reordering or extending a list says
  nothing at all. Associative blocks are unchanged: there a missing key really is a defect, and it
  still exits non-zero.

  There is deliberately no `--ignore` flag. An escape hatch over the failing class is how a check
  gets hollowed out, and the case that needed one was a false positive — fixed rather than made
  suppressible.

- **On Livewire 4 the consent gate locked every signed-in subject out of the application.** The gate
  has always allowlisted Livewire's own endpoints, because the bundled re-consent form is a Livewire
  component: ticking the box POSTs to Livewire's update channel, and a gate that refuses that channel
  refuses the one screen able to clear it. The allowlist matched the literal path `livewire/*` —
  which is where Livewire 3 mounted its endpoints and where Livewire 4 never does. Livewire 4 derives
  the prefix from `APP_KEY` (`/livewire-<8 hex>/`), so the pattern matched nothing: the form rendered,
  the box ticked, and submitting came back `409 legal_consent_required`. Only `logout` still worked,
  and signing back in returned to the same screen — the subject could neither consent nor leave.

  The gate now **resolves** Livewire's endpoints from the installation instead of assuming their
  path, and it covers the whole prefix rather than the update channel alone — the browser fetches
  Livewire's JavaScript over a separate request to a sibling path, and redirecting that one leaves
  the re-consent screen without the script that submits it. The literal `livewire/*` pattern stays
  for hosts pinned to the older layout, a custom `Livewire::setUpdateRoute()` path is resolved on
  top, and an installation without Livewire is untouched.

  **If you allowlisted `livewire-*` (or your own hash) to work around this, you can drop it.** Do not
  keep a hash: it is correct only for the `APP_KEY` it was read from, so it passes in development and
  silently stops matching in production.

  The reason this survived twelve green tests is worth stating: they all drove the form through
  `Livewire::test()`, which calls the component directly and raises no HTTP request, so the
  middleware never ran. The proof is now an HTTP request to the endpoint Livewire actually
  registered, resolved rather than written out.

- **A version tag could not be pushed at all.** The `pre-push` release gate required a green
  serial mutation run on the tagged tree before any `vX.Y.Z` push. Mutation is no longer
  mandatory anywhere — it runs nightly or on request, and a missing or stale score is
  information rather than a refusal — so `.mutation-ok` is now written only by a run nobody
  starts by hand. The result was not a slower release but an impossible one: the tag push could
  be satisfied only by bypassing the hook.

  The arm is **not** removed; a tag with no gate is worse than a tag with the wrong one. It now
  gates on `.just-all-ok`, the receipt that still means something — `just all` proves the
  statics, 100% line and type coverage, the cross-engine Postgres/MySql suites and the real
  browser suite on the exact tagged tree. It is the same receipt `main` already gates on, so a
  release that pushed `main` and then its tag needs no second run. The `main` arm is unchanged.

  The guard was rewritten to drive the real hook in a throwaway repository rather than to match
  its text, and it pins the new rule from both sides: a mutation receipt **alone** must never
  open a tag push, or the retired dependency could come back with every other assertion staying
  green.

## [0.12.0] - 2026-08-06

**Change notices reached the wrong people, or nobody, and said too little when they arrived.** This
release is mostly repair: three defects that each made a legally required notice fail silently, plus
the feature they were blocking — a notice that can finally say what changed.

**Read the upgrade guide before deploying.** The audience fix turns a path that reached nobody into
one that reaches every party to a contract, and the first sweep after upgrading is the first time
those notices actually leave the queue.

### Added

- **A notice can finally say WHAT changed.** Describe a pending change per document and locale, and
  the notice carries it — a headline, an impact statement, and a typed list of entries:

  ```php
  use Pushery\LegalConsent\Facades\ChangeItems;

  ChangeItems::for('terms', 'de')
      ->headline('Wir haben zwei Auftragsverarbeiter aufgenommen.')
      ->impact('Deine Kontodaten werden künftig auch in Irland verarbeitet.')
      ->added('Cloudflare, Inc.', partyName: 'Cloudflare, Inc.', partyLocation: 'Irland (EU)', purpose: 'Auslieferung')
      ->restricted('§ 7 Haftung', 'Haftung für einfache Fahrlässigkeit ausgeschlossen.')
      ->save();
  ```

  Two fields rather than one, because they are two obligations: § 327r Abs. 2 Satz 2 Nr. 1 BGB wants
  the characteristics of the change, and WP260 rev.01 Rz. 31 separately wants its likely impact.

  The publish **freezes** that draft onto the version inside the same transaction — a transition of
  the same row, not a copy, so the text on file and the text a subject saw cannot drift. Frozen
  means frozen: a database trigger on PostgreSQL and MySQL refuses UPDATE and DELETE, and a model
  hook covers SQLite.

  The six entry types (`added`, `removed`, `modified`, `clarified`, `extended`, `restricted`) are
  not a taxonomy of edits but the distinctions that decide what else the notice owes. `extended` is
  the one a naive list misses: something optional becoming mandatory reads as a bonus and is a
  narrowing. An adverse entry now makes the free-termination line mandatory whether or not the
  operator set `offers_termination`, because § 675g Abs. 2 Satz 3 BGB ties that notice to the change
  being disadvantageous rather than to a flag.

  Third-party entries carry the facets EDPB Opinion 22/2024 Rz. 22 expects — who, where, contact,
  purpose — named by legal function rather than as "sub-processor", so the same columns describe a
  new payment provider or joint controller.

  **The delta is rendered as mail LINES, never as a replacement view.** That is what puts it into
  `legal_notices.notice_body` and its sha256 automatically: a delta the subject can read but the
  proof row does not contain would leave the record understating what was communicated.

  Nothing about this is required. A version with no description renders exactly the notice it
  rendered before. `change_items.required` turns it into a release precondition once your process is
  ready; it ships off, because a package that started refusing existing releases the day it shipped
  a new field would be forcing a feature rather than offering one.

  `legal-consent:changes {key} {locale} [--clear]` prints or discards a pending description.
  `legal_documents.change_summary` is superseded and stays untouched — it is varchar(255), frozen
  after INSERT, and could never have held a list.

- **`legal-consent:dispatch-notices --dry-run`** reports the audience of every due version and
  sends nothing, writes no proof row and stamps no watermark. The non-gating modes reached nobody
  until this release, so the first real sweep afterwards is also the first time an operator's
  info-only changes actually leave the queue — for a large installation that is a fan-out they have
  never seen.
- **`notifications.max_recipients_per_run`** holds back any version whose audience exceeds it —
  **without stamping the watermark**, so nothing is lost and the same notice is still owed on the
  next run. Release it with `--force`, or raise the limit. It ships as `null`, and that default is
  deliberate: a limit that were on by default would withhold a legally required notice from every
  installation that never asked for one, which is the failure this release removes and the more
  expensive direction — under P2B Art. 3(3) a change implemented without notice is void, while an
  oversized send is merely expensive.
- **Every run now names the audience per version before it sends**, not only `--dry-run`. The size
  of a send is the one number an operator cannot recover afterwards, and the aggregate line at the
  end cannot say which version it belonged to.
- **`legal-consent:renotify {key} {locale} {version?}`** clears a version's notice watermark so the
  next sweep considers it again. It exists for the cohort the audience defect left behind: those
  versions are stamped as notified, so nothing would ever revisit them. It clears the watermark and
  nothing else, and reports a version that was never swept as such rather than as repaired.

### Changed

- **Deemed consent now requires a delivered notice, and refuses without one.**
  `legal-consent:close-objection-windows` deemed acceptance from the ledger alone: nothing ensured
  the § 308 Nr. 5 lit. b warning had ever been delivered, although that warning is a validity
  condition of the fiction rather than courtesy copy. Silence that binds without it produces a
  consent record the package can refute from its own proof table.

  It now deems only subjects carrying a `legal_notices` row for that version whose
  `mandatory_content_ok` is true, reads that proof once per batch rather than once per subject, and
  **exits non-zero naming how many subjects it refused** — a silent skip here is indistinguishable
  from "nobody was owed anything". A subject who objected, terminated, or already holds the version
  is not counted as a deficiency; only one who would otherwise have been bound.

  This was masked until this release, because the audience defect meant the fiction applied to
  nobody at all. **It also makes `durable_medium.proof` a prerequisite of deemed consent**: with it
  off no proof row is ever written, so the sweep refuses to close any window rather than deem an
  entire population against no evidence, and `legal-consent:doctor` reports the contradiction.

### Fixed

- **An info-only or deemed-consent change reached nobody at all.** The affected-subject resolver
  selected on a major-version bump, while the publisher refuses a non-gating mode on a major bump of
  a contract — so such a change is necessarily a minor bump, and a minor bump never satisfies
  "MAX(major) < this major". The two sets never intersected. The sweep reported success over an
  empty audience, wrote no proof row, and stamped `notified_at` anyway, so no re-run recovered it.
  The documented recipe for an info-only change was exactly this shape.

  The audience is now mode-dependent: a gating change still addresses only the subjects its
  middleware will block, and a non-gating change addresses **every current party**. A notice duty
  under § 327r Abs. 2 BGB, § 675g Abs. 1 BGB, P2B Art. 3(2) or DSA Art. 14(2) attaches to being a
  party, not to holding an outdated version — and under P2B Art. 3(3) a change implemented without
  notice is void, so under-reaching was the expensive direction.

  **This is a behavior change with a real fan-out.** See the upgrade guide before deploying.

- **Silence bound nobody either.** `legal-consent:close-objection-windows` shares that audience, and
  its decision carried the same assumption one layer down: it deemed acceptance only where the
  subject's major was behind the version's, which for a lawful deemed-consent change is never true.
  It now compares the version. Only the active version is ever swept and the publisher refuses a
  downgrade, so "not this version" is exactly "older than this version" here.

- **`mandatory_content_ok` could not be `false` for a re-consent notice, whatever the input.**
  The flag is written immutably into the `legal_notices` proof row and is meant to record whether
  the notice carried the line its notice mode legally requires. `ReconsentRequired` resolved that
  line through a helper that falls back to hardcoded German for every mandatory key, so a locale
  with no translation at all still certified as complete — a notice whose text resolved from
  nothing was recorded as compliant. The check now asks the translator directly. The fallback
  stays on the display path: a subject who receives the notice in the wrong language is better
  served than one who receives an empty mail, and it is the proof row's job to tell the truth
  about it.

- **A deemed-consent notice that offers free termination and then omits the line certified as
  complete.** For a payment contract § 675g Abs. 2 Satz 3 BGB puts the termination notice on the
  same footing as the § 308 Nr. 5 lit. b silence warning, so dropping it is the same class of
  defect. Checked only where it is owed — a version offering no termination right owes no line.

- **Every change notice after the first one in a sweep went out in the first one's language,
  while the proof row recorded the right one.** `legal-consent:dispatch-notices` notifies each
  due version in that version's locale, and it pinned that locale through
  `Notification::locale()`. On Laravel 13 the facade stores the locale on the channel manager,
  which memoizes a notification sender the first time anything is sent — so the sender keeps the
  locale of the first send for the rest of the process, and the queue path then overwrites each
  notification's own locale with that frozen value. A sweep publishing German, Spanish, Italian
  and Dutch versions therefore mailed German to all four audiences.

  The damage is not the wrong email. `legal_notices` is append-only, its rows are rendered under
  the version's locale directly, and they are the durable-medium evidence that a legally required
  notice went out — so the row certified a Spanish notice that was never sent, and neither the
  model nor the database trigger will let that row be corrected. The locale is now pinned on the
  notification itself, which the sender does not overwrite. If your own code calls
  `Notification::locale()` anywhere in the same process, it still freezes the sender for
  everything that follows, including this sweep.

- **The re-consent notice asked you to agree "in time" without ever saying by when.** The
  `contract.consequence` line carried no `:deadline` placeholder in any of the seven bundled
  locales, although the notification computes the date from `enforce_from` and has always passed
  it in. § 308 Nr. 5 lit. a BGB requires an adequate period for an express declaration and
  § 327r Abs. 2 Satz 2 Nr. 1 BGB the time of the change; a notice with no date states neither.
  A version with no enforcement date has no date to name and now uses a separate
  `contract.consequence_undated` line rather than rendering a gap. If you have published your own
  `notifications.php`, add both keys — the shipped English wording is
  `Please agree by :deadline …` and `Please agree in time …`.

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

[Unreleased]: https://github.com/pushery/legal-consent-for-laravel/compare/v0.25.2...HEAD
[0.25.3]: https://github.com/pushery/legal-consent-for-laravel/compare/v0.25.2...v0.25.3
[0.25.2]: https://github.com/pushery/legal-consent-for-laravel/compare/v0.25.1...v0.25.2
[0.25.1]: https://github.com/pushery/legal-consent-for-laravel/compare/v0.25.0...v0.25.1
[0.25.0]: https://github.com/pushery/legal-consent-for-laravel/compare/v0.24.0...v0.25.0
[0.24.0]: https://github.com/pushery/legal-consent-for-laravel/compare/v0.23.0...v0.24.0
[0.23.0]: https://github.com/pushery/legal-consent-for-laravel/compare/v0.22.0...v0.23.0
[0.22.0]: https://github.com/pushery/legal-consent-for-laravel/compare/v0.21.0...v0.22.0
[0.21.0]: https://github.com/pushery/legal-consent-for-laravel/compare/v0.20.0...v0.21.0
[0.20.0]: https://github.com/pushery/legal-consent-for-laravel/compare/v0.19.0...v0.20.0
[0.19.0]: https://github.com/pushery/legal-consent-for-laravel/compare/v0.18.0...v0.19.0
[0.18.0]: https://github.com/pushery/legal-consent-for-laravel/compare/v0.17.0...v0.18.0
[0.17.0]: https://github.com/pushery/legal-consent-for-laravel/compare/v0.16.1...v0.17.0
[0.16.1]: https://github.com/pushery/legal-consent-for-laravel/compare/v0.16.0...v0.16.1
[0.16.0]: https://github.com/pushery/legal-consent-for-laravel/compare/v0.15.0...v0.16.0
[0.15.0]: https://github.com/pushery/legal-consent-for-laravel/compare/v0.14.0...v0.15.0
[0.14.0]: https://github.com/pushery/legal-consent-for-laravel/compare/v0.13.0...v0.14.0
[0.13.0]: https://github.com/pushery/legal-consent-for-laravel/compare/v0.12.0...v0.13.0
[0.12.0]: https://github.com/pushery/legal-consent-for-laravel/compare/v0.11.0...v0.12.0
[0.11.0]: https://github.com/pushery/legal-consent-for-laravel/compare/v0.10.0...v0.11.0
[0.10.0]: https://github.com/pushery/legal-consent-for-laravel/compare/v0.9.0...v0.10.0
[0.9.0]: https://github.com/pushery/legal-consent-for-laravel/compare/v0.8.0...v0.9.0
[0.8.0]: https://github.com/pushery/legal-consent-for-laravel/compare/v0.7.0...v0.8.0
[0.7.0]: https://github.com/pushery/legal-consent-for-laravel/compare/v0.6.0...v0.7.0
[0.6.0]: https://github.com/pushery/legal-consent-for-laravel/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/pushery/legal-consent-for-laravel/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/pushery/legal-consent-for-laravel/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/pushery/legal-consent-for-laravel/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/pushery/legal-consent-for-laravel/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/pushery/legal-consent-for-laravel/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/pushery/legal-consent-for-laravel/releases/tag/v0.1.0
