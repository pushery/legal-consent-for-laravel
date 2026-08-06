<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Illuminate\Console\Command;
use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Models\Scopes\TenantScope;
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
 */
final class DoctorCommand extends Command
{
    protected $signature = 'legal-consent:doctor';

    protected $description = 'Report keys a published config file loses or keeps stale, without changing it.';

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

    public function handle(): int
    {
        $incoherent = $this->deemedConsentWithoutProof();

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

        if ($lost === [] && $stale === []) {
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
            foreach ($this->flatten($value, (string) $block) as $key => $default) {
                if (! $this->has($published, $key)) {
                    $lost[$key] = $default;
                }
            }
        }

        return $lost;
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
