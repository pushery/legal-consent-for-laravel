<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Closure;
use Illuminate\Console\Command;
use Pushery\LegalConsent\Enums\DocumentType;
use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\LegalConsentServiceProvider;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Models\Scopes\TenantScope;
use Pushery\LegalConsent\Support\DocumentMatrix;
use Pushery\LegalConsent\Support\RegistrationConsentRecorder;
use Pushery\WireKit\WireKitServiceProvider;
use Throwable;

/**
 * Report how a PUBLISHED config file differs from the package's own — without touching it.
 *
 * Publishing `config/legal-consent.php` freezes a copy, and `mergeConfigFrom()` is a FLAT
 * `array_merge(package, published)` (Illuminate\Support\ServiceProvider). That produces an
 * asymmetry which is invisible until something does not work:
 *
 *  - a whole NEW top-level block reaches the consumer, because the merge supplies it;
 *  - a key added INSIDE a block the published file already declares never arrives at all.
 *    The published block wins wholesale, so at runtime the key is not "undocumented", it is
 *    GONE — with the package's default silently replaced by whatever the old file said.
 *
 * The reverse rots too: a key the package has since removed lives on in the published file and
 * still reads like valid configuration, including entries pointing at classes that no longer
 * exist.
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
final class DoctorCommand extends Command
{
    protected $signature = 'legal-consent:doctor';

    protected $description = 'Report config keys a published file loses or keeps stale, and documents with no published version, without changing anything.';

    /**
     * Blocks that belong to the APP, not the package, so a difference is a choice rather than
     * drift. `documents` is the registry a consumer curates: removing the bundled `newsletter`
     * entry is the documented way to not have that document, and reporting it as "missing" would
     * train the reader to ignore this command.
     */
    private const array APP_OWNED = ['documents'];

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
        if ((bool) config('legal-consent.durable_medium.proof', true)) {
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
                if (! in_array("{$key}|{$locale}", $active, true)) {
                    $missing[] = "{$key} ({$locale})";
                }
            }
        }

        return $missing;
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

    public function handle(): int
    {
        $variantFinding = $this->uiVariantFinding();
        $firstUseFinding = self::describeFirstUseGate($this->publishedMandatoryKeys(), config('legal-consent.gate.first_use'));
        $unknownMode = $this->unknownRegistrationMode();
        $incoherent = $this->deemedConsentWithoutProof();
        $unpublished = $this->unpublishedCombinations();
        $uncacheable = $this->uncacheableKeys();

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

        if ($unknownMode !== null) {
            $this->newLine();
            $this->warn("legal-consent.registration.without_form_fields is '{$unknownMode}', which is not 'warn' or 'refuse'.");
            $this->line('  It falls back to `warn`, so registrations keep working — and that is why this is');
            $this->line('  worth saying: if you meant `refuse`, the one state you were guarding against is');
            $this->line('  still being recorded, into a table nothing can correct afterwards.');
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
            $this->line('  The read path does not fall back to the source on purpose, so nothing else says it.');
            $this->newLine();

            foreach ($unpublished as $combination) {
                $this->line("  <fg=yellow>?</> {$combination}");
            }

            $this->newLine();
            $this->line('  Publish the whole matrix idempotently: legal-consent:publish --all --editorial');
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

        // Every finding folds into ONE variable, and every return below reads it. Three separate
        // returns each restating the rule is how the legal contradiction fell out of the exit code
        // on the third one: the same deemed-consent-without-proof installation ended 1 or 0
        // depending on whether the published config happened to carry an unrelated stale key.
        $failed = $incoherent;

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
            $this->error('These keys exist in the package but NEVER reach your runtime config:');
            $this->line('  The published file already declares their top-level block, and the merge is flat,');
            $this->line('  so the published block wins wholesale and the package default is not applied.');
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
        $this->line('Nothing was changed. Copy the missing keys into the matching block of your published file;');
        $this->line('review the stale ones and delete what no longer applies.');

        // Only LOST keys are a defect — the runtime is not what the file says. A stale key is
        // hygiene, and exiting non-zero for it would make this command useless in a CI check. The
        // legal contradiction is folded in rather than restated, so config hygiene can never
        // decide it.
        return $failed || $lost !== [] ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Keys the package defines that the flat merge cannot deliver: nested under a top-level
     * block the published file already declares.
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
                if (! $this->has($published, $key)) {
                    $lost[$key] = $default;
                }
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
            if (! array_is_list($value) || ! array_is_list($current)) {
                continue;
            }

            // Scalars only: array_diff compares string casts, and a list holding anything else is
            // not a set of choices an operator made — it is a shape this report has nothing to say
            // about.
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
