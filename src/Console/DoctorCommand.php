<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Closure;
use Illuminate\Console\Command;
use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Models\Scopes\TenantScope;
use Pushery\LegalConsent\Support\DocumentMatrix;
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

    public function handle(): int
    {
        $incoherent = $this->deemedConsentWithoutProof();
        $unpublished = $this->unpublishedCombinations();
        $uncacheable = $this->uncacheableKeys();

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

        // $this->laravel->configPath(), never the config_path() helper: that one lives in
        // laravel/framework's Foundation, which this package deliberately does not require. A
        // consumer on a lean illuminate/* install would get a fatal error instead of a report.
        $publishedPath = $this->laravel->configPath('legal-consent.php');

        if (! is_file($publishedPath)) {
            $this->info('No published config — the package config applies in full, so nothing can drift.');

            return $incoherent ? self::FAILURE : self::SUCCESS;
        }

        $published = $this->load($publishedPath);
        $package = $this->load(__DIR__.'/../../config/legal-consent.php');

        $lost = $this->lostKeys($package, $published);
        $stale = $this->staleKeys($package, $published);
        $narrowed = $this->narrowedLists($package, $published);

        if ($lost === [] && $stale === [] && $narrowed === []) {
            $this->info('Published config is in sync with the package.');

            return $incoherent ? self::FAILURE : self::SUCCESS;
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
        // hygiene, and exiting non-zero for it would make this command useless in a CI check.
        return $lost === [] ? self::SUCCESS : self::FAILURE;
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
