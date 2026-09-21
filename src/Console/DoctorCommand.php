<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Pushery\LegalConsent\Enums\DocumentType;
use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Http\Middleware\EnsureLegalConsent;
use Pushery\LegalConsent\LegalConsentServiceProvider;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Models\Scopes\TenantScope;
use Pushery\LegalConsent\Support\DocumentMatrix;
use Pushery\LegalConsent\Support\LedgerHashChain;
use Pushery\LegalConsent\Support\RegistrationConsentRecorder;
use Pushery\LegalConsent\Support\SourceLanguageFallback;
use Pushery\WireKit\WireKitServiceProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;
use ValueError;

/**
 * Report how a PUBLISHED config file differs from the package's own — without touching it.
 *
 * Publishing `config/legal-consent.php` freezes a copy. The provider merges the shipped file under
 * it recursively ({@see LegalConsentServiceProvider::mergeConfigRecursivelyFrom()}), so a key added
 * inside a block the copy already declares still arrives. Two states are left that the merge cannot
 * reach, and both are invisible until something does not work:
 *
 *  - a STALE configuration cache. The provider does not merge while one exists, so a key the
 *    package adds after the cache was built is not "undocumented" at runtime, it is GONE until
 *    the cache is rebuilt;
 *  - a key the package has since removed lives on in the published file and still reads like
 *    valid configuration, including entries pointing at classes that no longer exist.
 *
 * This command names both, and deliberately changes nothing. Rewriting the file would discard
 * the operator's own values; `--force`-republishing does the same. Reporting is what a consumer
 * can act on — which is the whole gap, since nothing else in the package looks at config drift
 * (the other commands all check CONTENT drift).
 *
 * It also reports the other state nothing else names: a registered document with no published
 * version. That one is not config drift at all — it is what a correct installation looks like
 * before its first publish, and every page built on it renders empty in silence.
 *
 * EXIT CODES, because a gate step needs them to be a contract rather than a habit:
 * non-zero for a LOST key (the runtime is not what the file says) and for a config that is
 * internally contradictory. Zero for a stale key, an unpublished document, and a list the host
 * deliberately keeps shorter — all real findings worth reading, none of which means the
 * configuration is wrong. A step that goes red on a state the operator chose gets switched off,
 * and then the genuine findings go with it. There is no `--ignore` for the same reason in
 * reverse: an escape hatch over the failing class would hollow this out, and the case that
 * needed one was a false positive, which is fixed rather than made suppressible.
 */
#[AsCommand(name: 'legal-consent:doctor')]
final class DoctorCommand extends Command
{
    protected $signature = 'legal-consent:doctor';

    protected $description = 'Report config keys a published file loses or keeps stale, and documents with no published version, without changing anything.';

    /**
     * Blocks that belong to the APP, not the package, so a difference is a choice rather than
     * drift. `documents` is the registry a consumer curates: removing the bundled `newsletter`
     * entry is the documented way to not have that document, and reporting it as "missing" would
     * train the reader to ignore this command.
     *
     * Taken from the provider rather than written out again. The two answer the same question —
     * the provider stops merging into these blocks, this command stops judging them — and since
     * the merge stopped, they cannot disagree without being wrong: a block the host owns whole is
     * one whose entries genuinely do not reach the runtime, so a report asking the runtime would
     * name every shipped example they chose not to serve. A copy here would be a second place to
     * change and a first place to forget; a deliberate divergence needs its own reason, written
     * where it is made.
     */
    private const array APP_OWNED = LegalConsentServiceProvider::HOST_OWNED_REGISTRIES;

    /**
     * Is any active version relying on deemed consent while the durable-medium proof is switched
     * off? The two settings are individually valid and jointly contradictory, which is exactly the
     * class of problem a doctor exists to name.
     *
     * Reads the DATABASE, not only the config, so it reports a real contradiction rather than a
     * hypothetical one — an installation that never uses deemed consent is not misconfigured. A
     * database that is not migrated yet cannot contradict anything, hence the swallowed failure.
     */
    private function deemedConsentWithoutProof(): bool
    {
        if (config('legal-consent.durable_medium.proof', true)) {
            return false;
        }

        try {
            return LegalDocument::query()
                ->withoutGlobalScope(TenantScope::class)
                ->where('is_active', true)
                ->where('notice_mode', NoticeMode::DeemedConsent->value)
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Config keys holding a Closure, which `php artisan config:cache` cannot serialize.
     *
     * Two keys accept one — `gate.subject_filter` and `document_url` — and both document the
     * closure form FIRST and the caching consequence a couple of lines later. A reader copies the
     * example, not the footnote, so the shape that breaks is the shape that gets used.
     *
     * The failure is distributed in the worst possible way. Locally nothing caches, so it runs. The
     * package's own suite passes closures on purpose, so it runs. The first time anyone sees it is
     * `config:cache` in a deploy — after the merge, after a green gate, at the point where turning
     * back is expensive. Naming it here costs one line of output and moves the discovery to the
     * machine where the closure was written.
     *
     * Reported, never failed on: a closure is entirely valid until someone caches, and plenty of
     * installations never do.
     *
     * @return list<string>
     */
    private function uncacheableKeys(): array
    {
        $found = [];

        foreach (['gate.subject_filter', 'document_url'] as $key) {
            if (config("legal-consent.{$key}") instanceof Closure) {
                $found[] = $key;
            }
        }

        return $found;
    }

    /**
     * Configured (document, locale) combinations with no active published row.
     *
     * A fresh installation has an EMPTY `legal_documents` table, and the read path deliberately
     * does not fall back to the source — so every page built on `Consent::published()` renders
     * empty, with no error and no log. The configuration is correct, the sources are there, and
     * the legal pages are shells. Nothing in the package said so until this arm existed, and the
     * install instructions never named a publish step.
     *
     * @return list<string>
     */
    private function unpublishedCombinations(): array
    {
        try {
            $active = LegalDocument::query()
                ->withoutGlobalScope(TenantScope::class)
                ->where('is_active', true)
                ->get(['key', 'locale'])
                ->map(static fn (LegalDocument $row): string => "{$row->key}|{$row->locale}")
                ->all();
        } catch (Throwable) {
            // Not migrated yet. A schema that does not exist cannot be missing publications, and a
            // doctor that fatals on a fresh checkout helps nobody — this command is most useful
            // exactly when an installation is half-finished.
            return [];
        }

        $missing = [];

        foreach (DocumentMatrix::keys() as $key) {
            foreach (DocumentMatrix::locales() as $locale) {
                if (! in_array("{$key}|{$locale}", $active, true) && ! $this->servedByStandIn($key, $locale, $active)) {
                    $missing[] = "{$key} ({$locale})";
                }
            }
        }

        return $missing;
    }

    /**
     * Is a reader of this locale shown another language's version, rather than an empty page?
     *
     * An informational page always is, and an acknowledgment that sets `locale_fallback` is. Both
     * used to be reported here as rendering empty, which was false for the first from the day the
     * fallback existed. The rule is asked, not restated: {@see SourceLanguageFallback}.
     *
     * @param  array<array-key, string>  $active  "key|locale" for every active row
     */
    private function servedByStandIn(string $key, string $locale, array $active): bool
    {
        $basis = config("legal-consent.documents.{$key}.legal_basis");

        try {
            $type = DocumentType::fromLegalBasis(is_string($basis) ? $basis : 'contract');
        } catch (ValueError) {
            return false;
        }

        $default = config('legal-consent.default_locale', 'de');

        return array_any(SourceLanguageFallback::standInLocales($key, $type, $locale, is_string($default) && $default !== '' ? $default : 'de'), fn (string $candidate): bool => in_array("{$key}|{$candidate}", $active, true));
    }

    /**
     * `middleware.rights_routes` entries that name no registered route.
     *
     * The gate exempts a route by its NAME, so a typo exempts nothing, and the export or the
     * deletion it was meant to keep open sits behind the gate with a configuration that says
     * otherwise. That is a contradiction rather than a drift, so it fails the run. A value that is
     * not a string cannot name a route either, and is returned as it is so the report can say what
     * it was.
     *
     * @return list<mixed>
     */
    private function unknownRightsRoutes(): array
    {
        $names = config('legal-consent.middleware.rights_routes');

        if (! is_array($names)) {
            return [];
        }

        return array_values(array_filter(
            $names,
            static fn (mixed $name): bool => ! is_string($name) || ! Route::has($name),
        ));
    }

    /**
     * Does the gate guard a route, while a document that gates is registered and no route is named
     * where a subject exercises a data-protection right?
     *
     * A note rather than a failure. Whether the export and the deletion live behind the gate at all
     * is the application's to know: one that answers such requests by mail, or on a route outside
     * the guarded group, is right to name none. What the package owns is the QUESTION, because the
     * gate is its own and nothing else would ask it.
     */
    private function gateWithoutRightsRoutes(): bool
    {
        $declared = config('legal-consent.middleware.rights_routes');

        if (is_array($declared) && array_filter($declared, is_string(...)) !== []) {
            return false;
        }

        $gates = false;

        foreach (DocumentMatrix::keys() as $key) {
            $gates = $gates || in_array(config("legal-consent.documents.{$key}.legal_basis"), ['contract', 'acknowledgement'], true);
        }

        return $gates && $this->gateGuardsARoute();
    }

    /** Is the enforcement middleware on at least one registered route, directly or through a group? */
    private function gateGuardsARoute(): bool
    {
        $router = $this->laravel->make(Router::class);

        foreach ($router->getRoutes()->getRoutes() as $route) {
            foreach ($router->gatherRouteMiddleware($route) as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, EnsureLegalConsent::class)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The keys of the mandatory documents this installation has published and still serves.
     *
     * @return list<string>
     */
    private function publishedMandatoryKeys(): array
    {
        try {
            $keys = LegalDocument::query()
                ->withoutGlobalScope(TenantScope::class)
                ->where('is_active', true)
                ->where('requires_explicit_optin', false)
                ->whereIn('type', [DocumentType::ContractTerms->value, DocumentType::PrivacyNotice->value])
                ->orderBy('key')
                ->pluck('key')
                ->all();
        } catch (Throwable) {
            // Not migrated yet — the same answer its siblings give. A schema that does not exist
            // has published nothing, and a doctor that fatals on a fresh checkout is useless
            // exactly where it is needed most.
            return [];
        }

        // One key per document identity: the same contract published in seven locales is one
        // thing a subject accepts, and naming it seven times would read as seven problems.
        //
        // The filter and array_values() are EQUIVALENT under mutation: `key` is a non-null string
        // column, and the only readers, count() and implode(), ignore array keys. Both stay for the
        // list<string> this returns; measured 2026-09-14, static analysis rejects either removal.
        return array_values(array_unique(array_filter($keys, is_string(...))));
    }

    /**
     * What is worth saying about first-use gating, given what is published and how it is set.
     *
     * Pure on purpose, like {@see describeVariant()}: no config read and no query, so both answers
     * are reachable from a test rather than one of them shipping as prose nobody ever ran.
     *
     * Only ONE state is reported, and the omission matters. Gating that is switched ON is a
     * decision and is left alone. Gating that is OFF while mandatory documents are published is
     * the state a consumer cannot see: `outstanding()` filters on the notice mode of a version
     * CHANGE, so a subject who never accepted anything is not in it, is not counted anywhere, and
     * is served the application as if they had accepted. Nothing goes red, no row is written, and
     * the screen that would have asked them renders "everything current".
     *
     * @param  list<string>  $mandatoryKeys
     * @return array{0: string, 1: list<string>}|null
     */
    public static function describeFirstUseGate(array $mandatoryKeys, mixed $firstUseSetting): ?array
    {
        if ($mandatoryKeys === [] || $firstUseSetting === true) {
            return null;
        }

        return [
            'First-use gating is off, and '.count($mandatoryKeys).' mandatory document(s) are published: '.implode(', ', $mandatoryKeys).'.',
            [
                '  The enforcement middleware asks about a CHANGE. A subject who never accepted any',
                '  of these has had no change, so they are not asked, not counted, and served the',
                '  application as though they had accepted — in a ledger that cannot be corrected.',
                '',
                '  If your sign-up records consent (a checkbox on the registration form), this is',
                '  fine and expected. If it does not — an OAuth-only sign-in, an imported user base —',
                '  set `legal-consent.gate.first_use` to true AND mount the form as a first-use gate',
                '  on your consent route, or those people are never asked:',
                '',
                '      <livewire:legal-consent.reconsent-form :method="\Pushery\LegalConsent\Enums\ConsentMethod::FirstUseGate" />',
                '',
                '  Turning the switch on without that screen is a dead end, not a loop: the consent',
                '  route is allowlisted, so they land there and are told nothing is due.',
            ],
        ];
    }

    /**
     * What is worth saying about which view set is being served, or null when there is nothing.
     *
     * Thin on purpose: it reads the environment and hands the three facts to a pure describer. The
     * environment here cannot be varied — this package's own suite always has one WireKit
     * installed, so the below-the-floor answer is unreachable through this method and would ship
     * as prose nobody ever ran. The describer below is where that answer is exercised.
     *
     * @return array{0: string, 1: list<string>}|null the headline and its explanation
     */
    private function uiVariantFinding(): ?array
    {
        return self::describeVariant(
            config('legal-consent.ui.variant', 'auto'),
            class_exists(WireKitServiceProvider::class),
            LegalConsentServiceProvider::usesWireKitViews(),
        );
    }

    /**
     * The finding for a given variant setting and a given WireKit situation — no environment, no
     * config, so every combination is reachable.
     *
     * Only the two states a consumer cannot see for themselves are reported. An unstyled view
     * RENDERS — no exception, no log line, no red test — so "WireKit is installed and you are
     * getting the plain views anyway" is exactly the kind of fact that otherwise has to be noticed
     * by eye, on a screen nobody looks at twice.
     *
     * A deliberate `plain` with WireKit installed is NOT reported: that is a decision, and a doctor
     * that argues with decisions gets ignored on the finding that matters.
     *
     * @param  bool  $servesWireKit  what the variant resolved to, which for `auto` also carries the
     *                               version floor
     * @return array{0: string, 1: list<string>}|null
     */
    public static function describeVariant(mixed $variant, bool $wireKitInstalled, bool $servesWireKit): ?array
    {
        $known = ['auto', 'plain', 'wirekit'];

        if (! is_string($variant) || ! in_array($variant, $known, true)) {
            return [
                'legal-consent.ui.variant is not one of '.implode(', ', $known).'.',
                [
                    '  It is being treated as `auto`. A typo must not quietly decide which view set',
                    '  you serve — least of all by meaning `plain`, which is the state this key exists',
                    '  to make visible.',
                ],
            ];
        }

        if ($variant !== 'auto' || ! $wireKitInstalled || $servesWireKit) {
            return null;
        }

        return [
            'WireKit is installed, but the PLAIN consent views are being served.',
            [
                '  `auto` only serves the WireKit views from pushery/wirekit '
                    .LegalConsentServiceProvider::WIREKIT_MINIMUM.' upwards, because a Blade component',
                '  tag compiles unconditionally: serving views that name a component your WireKit does',
                '  not have would turn unstyled markup into a hard exception — on the re-consent gate,',
                '  at the moment a legal change lands.',
                '',
                '  Upgrade pushery/wirekit, or set legal-consent.ui.variant to `wirekit` to serve them',
                '  anyway, having read the line above.',
            ],
        ];
    }

    /**
     * A `registration.without_form_fields` value the recorder does not recognize.
     *
     * It falls back to `warn`, which is the safe direction — a typo must never be the thing that
     * starts failing registrations — and that is exactly why it has to be said out loud. An
     * operator who wrote `refuse` with a typo believes they are refusing, and the one state they
     * were guarding against goes on being recorded, in an append-only table.
     */
    /**
     * The tamper-evidence posture, when it is weaker than the operator is likely to believe.
     *
     * Two states, and the second is the one nothing else reports. `verify-ledger` says on every
     * run what an intact chain does and does not prove — but somebody only reads that after
     * choosing to run it, and this command is where a posture question belongs.
     *
     * @return list<array{0: string, 1: list<string>}> headline plus explanation, per finding
     */
    private function tamperEvidenceFindings(): array
    {
        // Gated on the FEATURE being on, not on the key being absent. Tamper evidence is opt-in
        // and off by default, so an unkeyed default installation is a choice rather than a gap —
        // warning there would fire on every consumer who never asked for the feature, and a
        // warning that always fires is one nobody reads.
        //
        // Switched ON without a key is the state worth naming: the operator asked for the
        // guarantee and has the weaker half of it.
        if (! filter_var(config('legal-consent.tamper_evidence', false), FILTER_VALIDATE_BOOL)) {
            return [];
        }

        $key = config('legal-consent.tamper_evidence_key');
        $keyed = is_string($key) && $key !== '';
        $findings = [];

        if (! $keyed) {
            $findings[] = [
                'Tamper evidence is ON, but legal-consent.tamper_evidence_key is not set.',
                [
                    '  An actor with table-write access can alter a row and re-chain its successors',
                    '  into a chain that verifies. Keying the hash closes that path; it does not close',
                    '  a tail truncation, which needs the head notarized somewhere else.',
                    '  This is a posture, not a defect — say so deliberately rather than by omission.',
                ],
            ];
        }

        // Only when keyed: unkeyed the root-proof check would not run anyway, and the finding
        // above already covers that ground. Two warnings for one posture is noise.
        if ($keyed && ! $this->rootBoundaryStamped()) {
            $findings[] = [
                'The chain-root boundary is not stamped, so the root-proof check cannot run.',
                [
                    '  A chain opened by a direct INSERT — fresh token, genesis link, no root proof —',
                    '  is what that check catches, and without the marker `verify-ledger` reports such',
                    '  a ledger as intact. Measured: the identical row is caught once the marker exists.',
                    '  Not migrated yet: run the package migrations with the key set; 000024 stamps it.',
                    '  Already migrated: running them again changes nothing. The first consent recorded',
                    '  with the key stamps it, and every row already in the ledger then counts as history.',
                ],
            ];
        }

        return $findings;
    }

    /**
     * Whether the chain-root boundary is stamped in this database. Migration 000024 stamps it when
     * it runs with a key; an installation migrated before the key gets it from the first consent
     * recorded with one.
     */
    private function rootBoundaryStamped(): bool
    {
        if (! Schema::hasTable('legal_ledger_markers')) {
            return false;
        }

        return DB::table('legal_ledger_markers')
            ->where('name', LedgerHashChain::ROOT_BOUNDARY_MARKER)
            ->exists();
    }

    private function unknownRegistrationMode(): ?string
    {
        // The literal, for the same reason the provider uses one: the config-drift test reads
        // inline defaults out of the SOURCE and cannot evaluate a class constant, so a constant
        // here would drop out of that comparison without a word. The constants below are the
        // vocabulary, which is a different job.
        $value = config('legal-consent.registration.without_form_fields', 'warn');

        $known = [
            RegistrationConsentRecorder::WITHOUT_FORM_FIELDS_WARN,
            RegistrationConsentRecorder::WITHOUT_FORM_FIELDS_REFUSE,
        ];

        if (is_string($value) && in_array($value, $known, true)) {
            return null;
        }

        return is_scalar($value) ? (string) $value : get_debug_type($value);
    }

    /**
     * An editor route the admin overview was told to link to and cannot.
     *
     * The overview degrades on purpose: a name that is not registered, or one these two parameters
     * cannot fill, leaves the cells unlinked rather than turning a read-only screen into an error
     * page. That is the right behavior and the wrong silence — an operator who set the key sees an
     * overview that looks exactly like one where nobody set it. This is where they find out.
     *
     * Reported, never enforced. Leaving it unset is the shipped default and a complete answer.
     *
     * @return array{0: string, 1: string}|null the configured name and what is wrong with it
     */
    private function unresolvableEditorRoute(): ?array
    {
        $name = config('legal-consent.admin.editor_route');

        if (! is_string($name) || $name === '') {
            return null;
        }

        if (! Route::has($name)) {
            return [$name, 'is not a registered route name'];
        }

        $key = DocumentMatrix::keys()[0] ?? null;
        $locales = config('legal-consent.locales');
        $locale = is_array($locales) ? ($locales[0] ?? null) : null;

        // Nothing registered to link TO yet. The route is fine as far as anything here can tell,
        // and inventing a key to probe with would report on a document that does not exist.
        if (! is_string($key) || ! is_string($locale)) {
            return null;
        }

        try {
            route($name, [$key, $locale]);
        } catch (UrlGenerationException) {
            return [$name, 'is registered, but a document key and a locale do not fill its parameters'];
        }

        return null;
    }

    /**
     * The store the document cache actually reads from, when that store is the database.
     *
     * THE ADVICE EXISTED AND ONLY A DOCBLOCK CARRIED IT. `EnforceableDocumentCache` explains
     * that the enforceable set is asked for four times per request and that on Laravel's default
     * `database` store each of those is a SELECT against the cache table — so the per-request memo
     * hands part of its saving straight back. A consumer on a default install is in exactly that
     * state, has done nothing wrong, and nothing anywhere tells them.
     *
     * Reported, never enforced: `database` is a legitimate choice on a small install and on a host
     * with no Redis, and a doctor that refuses a working configuration is a doctor people stop
     * running. What it removes is the silence.
     *
     * @return array{0: string, 1: string}|null the resolved store name and where it came from
     */
    private function databaseBackedDocumentCache(): ?array
    {
        $configured = config('legal-consent.cache.store');
        $store = is_string($configured) && $configured !== '' ? $configured : null;
        $origin = $store === null ? 'cache.default' : 'legal-consent.cache.store';

        if ($store === null) {
            $default = config('cache.default');
            $store = is_string($default) ? $default : null;
        }

        // EQUIVALENT under mutation without this return: the lookup below would read
        // `cache.stores..driver`, which is null, and return null all the same. It stays because it
        // says the case out loud.
        if ($store === null) {
            return null;
        }

        // The DRIVER, not the store name. A store called `database` may be backed by anything, and
        // a store called `documents` may be backed by the database — reading the name would be a
        // guess in both directions.
        $driver = config("cache.stores.{$store}.driver");

        return $driver === 'database' ? [$store, $origin] : null;
    }

    /**
     * A configured notification channel that cannot deliver, so a notice about a changed legal text
     * fails at the one moment somebody needed it.
     *
     * ONLY `database` IS ANSWERABLE FROM HERE, and the limit is stated rather than implied.
     * Whether a mailer reaches its host is a question no doctor can answer without sending
     * something, and a check that pretended otherwise would print a green line over a question it
     * never asked — the failure mode this whole command exists against. What IS answerable is the
     * one that leaves no trace in the application until a queue worker log is read: `database` is in
     * the channel list and Laravel's `notifications` table was never migrated, so every database
     * notification job fails.
     *
     * The mail is not lost with it, and that changes how loud this is. Laravel dispatches one
     * queued job PER CHANNEL, so a missing table fails the database job alone; a consumer reported
     * this as "the retry re-sends the mail" and it does not. What is lost is the in-app record, on
     * an install that asked for one.
     *
     * Reported, never enforced, like the cache finding above: the exit code of this command is for
     * a configuration that contradicts itself, and this one is merely broken in a way an operator
     * can fix in one migration.
     *
     * @return list<string> the configured channels that cannot deliver, in configured order
     */
    private function undeliverableNotificationChannels(): array
    {
        $configured = config('legal-consent.notifications.channels', ['mail', 'database']);
        $channels = is_array($configured) ? array_values(array_filter($configured, is_string(...))) : [];

        // The same fallback `ChangeNotification::via()` applies, read the same way: an empty or
        // unreadable list means the notification sends on the package's defaults, and a doctor that
        // reported "no channels" there would describe a configuration nothing uses.
        if ($channels === []) {
            $channels = ['mail', 'database'];
        }

        if (! in_array('database', $channels, true)) {
            return [];
        }

        // No `try` around this, and the coverage floor is what settled it. By the time this runs,
        // `handle()` has already read the database several times -- the tamper-evidence findings ask
        // `Schema::hasTable()` themselves, and the unpublished-combination check queries. An
        // unreachable database has therefore already ended the command, so a guard here is a second
        // answer to a question an earlier line answered by throwing, and a line no run can enter.
        return Schema::hasTable('notifications') ? [] : ['database'];
    }

    /**
     * Everything this report can only learn by asking the database, or null when it could not ask.
     *
     * ## WHY THE WHOLE HALF IS WRAPPED AND NOT EACH READER
     *
     * A connection that is refused is refused for all of them, so a guard per reader would answer
     * the same question five times and produce five identical sentences. It is also the honest
     * grouping: what is unknown here is not "the tamper chain" or "the channel" but whether the
     * report could look at the database at all.
     *
     * ## AND IT IS A REPORT, NOT A FAILURE
     *
     * This command used to end on the exception. A consumer had it in the statics stage of their
     * gate — which boots the application and deliberately reaches no external service, because
     * nothing in that chain had ever needed one — and the upgrade turned a config check into a
     * red lane with a `Connection refused` in it. In the next repository to adopt that arm the
     * same failure would read as a package defect.
     *
     * So an unreachable database makes these sections say they could not be checked, which is the
     * pattern this report already uses for the mailer: *whether a mailer reaches its host cannot be
     * answered without sending something, and the command says so.* What it must never do is let
     * "could not ask" read as "nothing to report".
     *
     * The shapes are each reader's own, copied from their docblocks rather than guessed at — a
     * type written from memory here would have been a claim about six methods at once.
     *
     * @return array{
     *     firstUse: array{0: string, 1: list<string>}|null,
     *     incoherent: bool,
     *     unpublished: list<string>,
     *     tamper: list<array{0: string, 1: list<string>}>,
     *     databaseCache: array{0: string, 1: string}|null,
     *     undeliverable: list<string>,
     * }|null
     */
    private function databaseFindings(): ?array
    {
        try {
            return [
                'firstUse' => self::describeFirstUseGate($this->publishedMandatoryKeys(), config('legal-consent.gate.first_use')),
                'incoherent' => $this->deemedConsentWithoutProof(),
                'unpublished' => $this->unpublishedCombinations(),
                'tamper' => $this->tamperEvidenceFindings(),
                'databaseCache' => $this->databaseBackedDocumentCache(),
                'undeliverable' => $this->undeliverableNotificationChannels(),
            ];
        } catch (QueryException) {
            // The connection, not the schema. A database that is THERE but not migrated is a state
            // every reader above already handles on its own — that is the half-finished
            // installation a doctor earns its name on, and swallowing it here would take those
            // findings away from the one installation that needs them most.
            return null;
        }
    }

    public function handle(): int
    {
        $variantFinding = $this->uiVariantFinding();
        $unknownMode = $this->unknownRegistrationMode();
        $editorRoute = $this->unresolvableEditorRoute();
        $uncacheable = $this->uncacheableKeys();
        $refusedFallbacks = SourceLanguageFallback::refusedKeys();
        $unknownRightsRoutes = $this->unknownRightsRoutes();
        $rightsUndeclared = $this->gateWithoutRightsRoutes();

        $database = $this->databaseFindings();

        $firstUseFinding = $database['firstUse'] ?? null;
        $incoherent = $database['incoherent'] ?? false;
        $unpublished = $database['unpublished'] ?? [];
        $tamperFindings = $database['tamper'] ?? [];
        $databaseCache = $database['databaseCache'] ?? null;
        $undeliverable = $database['undeliverable'] ?? [];

        if ($database === null) {
            $this->newLine();
            $this->warn('Some checks need a database, and this one could not be reached:');
            $this->line('  The published documents, the tamper-evidence chain, the document cache and the');
            $this->line('  `database` notification channel are all read from it, so none of them was checked.');
            $this->line('  Everything below is about your CONFIGURATION, which needs no connection.');
            $this->newLine();
            $this->line('  If this runs in a quality stage that reaches no services on purpose, that is the');
            $this->line('  whole explanation and there is nothing to fix. Run it again where the database is');
            $this->line('  up to get the other half.');
            $this->newLine();
        }

        if ($variantFinding !== null) {
            [$headline, $explanation] = $variantFinding;

            $this->newLine();
            $this->warn($headline);

            foreach ($explanation as $line) {
                $this->line($line);
            }

            $this->newLine();
        }

        if ($firstUseFinding !== null) {
            [$headline, $explanation] = $firstUseFinding;

            $this->newLine();
            $this->warn($headline);

            foreach ($explanation as $line) {
                $this->line($line);
            }

            $this->newLine();
        }

        foreach ($tamperFindings as [$headline, $explanation]) {
            $this->newLine();
            $this->warn($headline);

            foreach ($explanation as $line) {
                $this->line($line);
            }

            $this->newLine();
        }

        if ($editorRoute !== null) {
            [$routeName, $reason] = $editorRoute;

            $this->newLine();
            $this->warn("legal-consent.admin.editor_route is '{$routeName}'.");
            $this->line("  It {$reason}.");
            $this->line('  The admin overview links every cell to that route when it can. It cannot, so the');
            $this->line('  cells are plain text — which looks exactly like an installation that never set the');
            $this->line('  key, and is the reason this line exists. The parameters are filled by position:');
            $this->line('  the document key first, the locale second.');
            $this->newLine();
        }

        if ($unknownMode !== null) {
            $this->newLine();
            $this->warn("legal-consent.registration.without_form_fields is '{$unknownMode}', which is not 'warn' or 'refuse'.");
            $this->line('  It falls back to `warn`, so registrations keep working — and that is why this is');
            $this->line('  worth saying: if you meant `refuse`, the one state you were guarding against is');
            $this->line('  still being recorded, into a table nothing can correct afterwards.');
            $this->newLine();
        }

        if ($databaseCache !== null) {
            [$store, $origin] = $databaseCache;

            $this->newLine();
            $this->warn("The document cache resolves to '{$store}', which is a database-backed store ({$origin}).");
            $this->line('  The enforceable set is asked for four times in a documented request — once from the');
            $this->line('  gate middleware, three times from the banner — so on this store that is four SELECTs');
            $this->line('  against the cache table for one global fact. The per-request memo removes the repeats');
            $this->line('  within a request and cannot remove the first read.');
            $this->line('  Point LEGAL_CONSENT_CACHE_STORE at a store that is not the database (redis, memcached,');
            $this->line('  an in-memory octane store) and the read stops touching it at all.');
            $this->line('  This is a note, not a fault: `database` works, and on a small install it is a reasonable');
            $this->line('  choice. It is here because nothing else says it.');
            $this->newLine();
        }

        if ($uncacheable !== []) {
            $this->newLine();
            $this->warn('These config values are closures, so `php artisan config:cache` will fail:');
            $this->line('  It aborts the whole cache with a LogicException naming the key — in a deploy,');
            $this->line('  after everything else has already passed. Nothing local reproduces it.');
            $this->newLine();

            foreach ($uncacheable as $key) {
                $this->line("  <fg=yellow>?</> legal-consent.{$key}");
            }

            $this->newLine();
            $this->line('  Move the body into an invokable class and configure its class-string instead.');
            $this->line('  Both keys resolve one from the container, so the behavior is unchanged.');
            $this->newLine();
        }

        if ($unpublished !== []) {
            $this->newLine();
            $this->warn('These documents are registered but have no published version:');
            $this->line('  A page reading `Consent::published()` renders EMPTY for them — no error, no log.');
            $this->line('  Nothing is published in that language, and the document may not fall back to');
            $this->line('  another, so nothing else says it.');
            $this->newLine();

            foreach ($unpublished as $combination) {
                $this->line("  <fg=yellow>?</> {$combination}");
            }

            $this->newLine();
            $this->line('  Publish the whole matrix idempotently: legal-consent:publish --all --editorial');
            $this->newLine();
        }

        if ($undeliverable !== []) {
            $this->newLine();
            $this->warn('These notification channels are configured but cannot deliver:');

            foreach ($undeliverable as $channel) {
                $this->line("  <fg=yellow>?</> {$channel} — Laravel's `notifications` table does not exist.");
            }

            $this->line('  Every database notification job fails; the mail of the same notice still goes out,');
            $this->line('  because Laravel queues one job per channel. What is lost is the in-app record.');
            $this->newLine();
            $this->line('  Create the table: php artisan make:notifications-table && php artisan migrate');
            $this->line('  Or drop the channel: legal-consent.notifications.channels');
            $this->newLine();
        }

        if ($incoherent) {
            // A configuration that is internally contradictory rather than merely drifted: the
            // package cannot lawfully bind anyone by silence without the proof it is told not to
            // write, so close-objection-windows refuses to run. Better to read that here than to
            // discover it from a ledger that stays empty.
            $this->newLine();
            $this->error('Deemed consent is configured, but `durable_medium.proof` is off.');
            $this->line('  Silence binds only where the § 308 Nr. 5 lit. b warning was demonstrably delivered,');
            $this->line('  and that proof is the legal_notices row this setting suppresses. While it stays off,');
            $this->line('  `legal-consent:close-objection-windows` refuses to close any window.');
            $this->newLine();
        }

        if ($rightsUndeclared) {
            $this->newLine();
            $this->warn('The consent gate guards your routes, and no route is named where a subject exports or deletes their data.');
            $this->line('  While a subject owes a document, the gate stops every route it guards except its own');
            $this->line('  ways out. Access, portability and erasure (Art. 15, 20, 17 GDPR) do not depend on');
            $this->line('  accepting a new text, so name the routes that serve them — the page with the button as');
            $this->line('  well as the action behind it:');
            $this->newLine();
            $this->line("      'middleware' => ['rights_routes' => ['profile.edit', 'profile.export']],");
            $this->newLine();
            $this->line('  If your application answers these requests outside the routes the gate guards, there');
            $this->line('  is nothing to change, and this note stays because only you can know that.');
            $this->newLine();
        }

        if ($refusedFallbacks !== []) {
            $this->newLine();
            $this->error('`locale_fallback` is set on a document that binds:');

            foreach ($refusedFallbacks as $key) {
                $this->line("  <fg=red>x</> legal-consent.documents.{$key}");
            }

            $this->line('  A contract and a consent never fall back to another language, so the key does nothing');
            $this->line('  there: a release still covers every configured locale, and a reader of an untranslated');
            $this->line('  one is shown nothing. Binding somebody to a text in a language they may not read is what');
            $this->line('  that refusal prevents. Remove the key, or set the document\'s `legal_basis` to');
            $this->line('  `acknowledgement` if taking notice is all it asks for.');
            $this->newLine();
        }

        if ($unknownRightsRoutes !== []) {
            $this->newLine();
            $this->error('These `middleware.rights_routes` name no route:');

            foreach ($unknownRightsRoutes as $name) {
                $this->line('  <fg=red>x</> '.(is_string($name) ? $name : get_debug_type($name)));
            }

            $this->line('  The gate exempts a route by its name, so a name that matches nothing exempts nothing,');
            $this->line('  and the export or the deletion it was meant to keep open is behind the gate. Use the');
            $this->line('  name `php artisan route:list` shows.');
            $this->newLine();
        }

        // Every finding folds into ONE variable, and every return below reads it. Three separate
        // returns each restating the rule is how the legal contradiction fell out of the exit code
        // on the third one: the same deemed-consent-without-proof installation ended 1 or 0
        // depending on whether the published config happened to carry an unrelated stale key.
        $failed = $incoherent || $refusedFallbacks !== [] || $unknownRightsRoutes !== [];

        // $this->laravel->configPath(), never the config_path() helper: that one lives in
        // laravel/framework's Foundation, which this package does not import a symbol from. The
        // methods used here are on Illuminate\Contracts\Foundation\Application, the type
        // `$this->laravel` already has, so the report does not depend on a global function.
        $publishedPath = $this->laravel->configPath('legal-consent.php');

        if (! is_file($publishedPath)) {
            $this->info('No published config — the package config applies in full, so nothing can drift.');

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $published = $this->load($publishedPath);
        $package = $this->load(__DIR__.'/../../config/legal-consent.php');

        $lost = $this->lostKeys($package, $published);
        $stale = $this->staleKeys($package, $published);
        $narrowed = $this->narrowedLists($package, $published);

        if ($lost === [] && $stale === [] && $narrowed === []) {
            $this->info('Published config is in sync with the package.');

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        if ($lost !== []) {
            $this->newLine();
            $this->error('These keys exist in the package and do not reach your runtime config:');
            $this->line('  Asked of the runtime, not of your file: this package merges its defaults UNDER a');
            $this->line('  published config recursively, so a key your file omits normally still arrives.');
            $this->line('  These did not, and a STALE config cache is the usual reason — one built before');
            $this->line('  the package added the key. A cached configuration skips the merge and serves');
            $this->line('  what it captured, so the key can only appear after the cache is rebuilt.');
            $this->newLine();

            foreach ($lost as $key => $value) {
                $this->line("  <fg=red>-</> {$key}  <fg=gray>(package default: {$value})</>");
            }
        }

        if ($stale !== []) {
            $this->newLine();
            $this->warn('These keys exist only in your published file — the package no longer defines them:');
            $this->line('  They still read like valid configuration. An entry naming a class that has since');
            $this->line('  been removed fails at resolve time, pointing at your config rather than the upgrade.');
            $this->newLine();

            foreach ($stale as $key => $value) {
                $this->line("  <fg=yellow>?</> {$key}  <fg=gray>(your value: {$value})</>");
            }
        }

        if ($narrowed !== []) {
            $this->newLine();
            $this->warn('Your file carries fewer entries than the package default in these lists:');
            $this->line('  A list is ONE value — its length is your decision, not drift. `locales` is which');
            $this->line('  legal documents exist in your app, and adopting the default to silence a check');
            $this->line('  would mean publishing a second binding text. Named here so you can confirm it.');
            $this->newLine();

            foreach ($narrowed as $block => $missing) {
                $this->line("  <fg=yellow>?</> {$block}  <fg=gray>(not in your list: ".implode(', ', $missing).')</>');
            }
        }

        $this->newLine();
        $this->line('Nothing was changed.');
        $this->newLine();
        $this->line('Run `php artisan config:clear` and this command again: a key that arrives then was only');
        $this->line('cached away, and your file needs nothing. Add it by hand only where it stays missing — a');
        $this->line('package default copied into your file stops following the package, which is what the');
        $this->line('merge exists to avoid. Review the stale ones and delete what no longer applies.');

        // Only LOST keys are a defect, and that word is earned now: the key is absent from the
        // RUNTIME, not merely from the file. A key the merge still delivers no longer reaches this
        // line at all, which is what takes a permanently red step off a correct configuration.
        // A stale key is hygiene and exits 0 — failing on it would make this command
        // useless in a CI check. The legal contradiction is folded in rather than restated, so
        // config hygiene can never decide it.
        return $failed || $lost !== [] ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Keys the package defines that do not reach the runtime.
     *
     * ## ASKED OF THE RUNTIME, NOT OF TWO FILES, AND THAT IS THE WHOLE CORRECTION
     *
     * This used to diff the shipped config against the published one and report every key the file
     * did not name, with a sentence that argued from Laravel's `mergeConfigFrom()`: *"the merge is
     * flat, so the published block wins wholesale."* This package does not use that merge for its
     * own config. {@see LegalConsentServiceProvider::mergeConfigRecursivelyFrom()} descends into
     * every map, precisely so a key added inside an already-published block still arrives.
     *
     * So the report named keys that were reaching the runtime perfectly well — and `$lost` drives
     * the exit code, which made it a red step over a configuration with nothing wrong in it. It was
     * measured by a consumer whose integration branch had gone red over exactly that, on a published
     * file missing keys the recursive merge was delivering the whole time.
     *
     * AND THE ADVICE WAS WORSE THAN THE RED RUN. "Copy the missing keys into the matching block"
     * writes package defaults into a published file and freezes them on the day the package
     * improves them — the exact state the recursive merge exists to prevent.
     *
     * Asking the runtime needs no knowledge of how the merge works, and it stays right if that ever
     * changes. It also reports the one case that is genuinely still lost, and which no comparison
     * of two files can see: a STALE configuration cache. `config:cache` builds from a full
     * bootstrap, so the merge IS in the cached file — but the provider skips merging while a cache
     * exists, so a key the package adds AFTERWARDS never arrives until the cache is rebuilt. That
     * is a deployment state, not a file state, and it is the one this report now names.
     *
     * A whole top-level block missing from the published file is NOT reported — the merge
     * supplies it, so it arrives intact.
     *
     * @param  array<array-key, mixed>  $package
     * @param  array<array-key, mixed>  $published
     * @return array<string, string>
     */
    private function lostKeys(array $package, array $published): array
    {
        $lost = [];

        foreach ($this->comparableBlocks($package, $published) as $block => [$value]) {
            // A LIST is one value, and the index is order, not identity. Walking into it reported
            // `locales.1` as a lost key whenever the host carried FEWER locales than the package —
            // naming a value ("en") that was reaching the runtime perfectly well, at index 0.
            // Sets are compared in narrowedLists() instead, and not as a defect.
            if (array_is_list($value)) {
                continue;
            }

            foreach ($this->flatten($value, $block) as $key => $default) {
                if ($this->has($published, $key)) {
                    continue;
                }

                // The file does not name it. That is a question about documentation until the
                // runtime says otherwise — and only the runtime can, because the merge is what
                // stands between the two.
                if (config()->has("legal-consent.{$key}")) {
                    continue;
                }

                $lost[$key] = $default;
            }
        }

        return $lost;
    }

    /**
     * Top-level LISTS whose published copy is missing a member of the package default.
     *
     * Reported separately, and never as a failure. For an associative block a missing key is a
     * defect — the runtime is not what the file says. For a list it is usually the decision
     * itself: `locales` is which legal documents exist in this application, and an app that runs
     * in one language carries one. Adopting the package's list to satisfy a check would mean
     * publishing a second binding legal text, which is a worse outcome than the finding.
     *
     * The naming matters too. The old report said the package value "NEVER reaches your runtime
     * config" about a value that did reach it; this one says which member of the default the host
     * does not carry, and asks whether that is intended.
     *
     * @param  array<array-key, mixed>  $package
     * @param  array<array-key, mixed>  $published
     * @return array<string, list<string>>
     */
    private function narrowedLists(array $package, array $published): array
    {
        $narrowed = [];

        foreach ($this->comparableBlocks($package, $published) as $block => [$value, $current]) {
            // A `break` here is EQUIVALENT under mutation only by accident of the package config:
            // `locales` is its one list and comes before every block, so nothing is left to skip. A
            // list added further down would be skipped silently, which is why this is `continue`.
            if (! array_is_list($value) || ! array_is_list($current)) {
                continue;
            }

            // Scalars only: array_diff compares string casts, and a list holding anything else is
            // not a set of choices an operator made — it is a shape this report has nothing to say
            // about.
            //
            // On the package side the filter, the describe() map and array_values() are EQUIVALENT
            // under mutation: its one list holds strings, describe() hands a string back unchanged,
            // and implode() ignores keys. They stay for the list<string> this returns (measured
            // 2026-09-14: static analysis rejects each removal), and on the published side the
            // filter carries real weight, because a hand-edited list can hold an array.
            $missing = array_values(array_map($this->describe(...), array_diff(
                array_filter($value, is_scalar(...)),
                array_filter($current, is_scalar(...)),
            )));

            if ($missing !== []) {
                $narrowed[$block] = $missing;
            }
        }

        return $narrowed;
    }

    /**
     * The top-level blocks where a package/published comparison is meaningful at all.
     *
     * Four reasons to skip one, and each is a `continue` rather than a `break` on purpose: the
     * app-owned `documents` registry is present in every consumer's file, so abandoning the walk
     * at the first skip would stop the comparison before it started.
     *
     * Each entry is the package block and the published block side by side, so a caller cannot
     * re-read one of them off the raw array and lose the guarantee that both are arrays.
     *
     * @param  array<array-key, mixed>  $package
     * @param  array<array-key, mixed>  $published
     * @return array<string, array{0: array<array-key, mixed>, 1: array<array-key, mixed>}>
     */
    private function comparableBlocks(array $package, array $published): array
    {
        $blocks = [];

        foreach ($package as $block => $value) {
            if (in_array($block, self::APP_OWNED, true)) {
                continue;
            }
            if (! array_key_exists($block, $published)) {
                continue;
            }
            if (! is_array($value)) {
                continue;
            }
            if (! is_array($published[$block])) {
                continue;
            }

            // The cast is EQUIVALENT under mutation, because PHP stores a numeric string key as an
            // int either way. It is there for the string keys the return type promises.
            $blocks[(string) $block] = [$value, $published[$block]];
        }

        return $blocks;
    }

    /**
     * Keys the published file carries that the package no longer defines.
     *
     * @param  array<array-key, mixed>  $package
     * @param  array<array-key, mixed>  $published
     * @return array<string, string>
     */
    private function staleKeys(array $package, array $published): array
    {
        $stale = [];

        foreach ($published as $block => $value) {
            if (in_array($block, self::APP_OWNED, true)) {
                continue;
            }

            // A scalar top-level entry (`default_locale`) is already a complete key — flattening
            // it would build `default_locale.default_locale`, which exists nowhere and would be
            // reported as stale on a perfectly synchronized file.
            if (! is_array($value) || array_is_list($value)) {
                if (! array_key_exists($block, $package)) {
                    // EQUIVALENT under mutation for the reason given in comparableBlocks().
                    $stale[(string) $block] = $this->describe($value);
                }

                continue;
            }

            foreach ($this->flatten($value, (string) $block) as $key => $current) {
                if (! $this->has($package, $key)) {
                    $stale[$key] = $current;
                }
            }
        }

        return $stale;
    }

    /**
     * Flatten to dotted keys, stopping at a LIST (a sequential array is one value the operator
     * chose — reporting `locales.3` as a missing key would be noise, not a finding).
     *
     * @param  array<array-key, mixed>  $values
     * @return array<string, string>
     */
    private function flatten(array $values, string $prefix): array
    {
        $flat = [];

        foreach ($values as $key => $value) {
            $dotted = "{$prefix}.{$key}";

            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $flat += $this->flatten($value, $dotted);

                continue;
            }

            $flat[$dotted] = $this->describe($value);
        }

        return $flat;
    }

    /**
     * Does a dotted key exist in the array? Uses array_key_exists all the way down, never
     * isset(): a key explicitly set to null IS declared, and treating it as absent would report
     * a deliberate null as drift.
     *
     * @param  array<array-key, mixed>  $values
     */
    private function has(array $values, string $key): bool
    {
        $cursor = $values;

        foreach (explode('.', $key) as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return false;
            }

            $cursor = $cursor[$segment];
        }

        return true;
    }

    private function describe(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_array($value) => '['.count($value).' items]',
            is_scalar($value) => (string) $value,
            default => get_debug_type($value),
        };
    }

    /** @return array<array-key, mixed> */
    private function load(string $path): array
    {
        $values = require $path;

        return is_array($values) ? $values : [];
    }
}
