<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Illuminate\Console\Command;
use Pushery\LegalConsent\Support\LegalDriftChecker;

/**
 * Report (never fix) any legal document whose live source text has drifted from its
 * active published version. Meant for a daily schedule + CI; a non-zero exit forces the
 * operator to publish a new, materiality-classified version before the change takes effect.
 */
final class CheckDriftCommand extends Command
{
    protected $signature = 'legal-consent:check-drift {key? : Only this document key} {locale? : Only this locale}';

    protected $description = 'Report legal documents whose source text has drifted from the published version.';

    public function handle(LegalDriftChecker $checker): int
    {
        $key = $this->argument('key');
        $locale = $this->argument('locale');

        $keys = is_string($key) ? [$key] : array_keys((array) config('legal-consent.documents', []));
        $locales = is_string($locale) ? [$locale] : $this->configuredLocales();

        $drifts = [];

        foreach ($keys as $documentKey) {
            foreach ($locales as $documentLocale) {
                $reason = $checker->driftFor((string) $documentKey, (string) $documentLocale);

                if ($reason !== null) {
                    $drifts[] = $reason;
                }
            }
        }

        if ($drifts === []) {
            $this->info('No legal-document drift detected.');

            return self::SUCCESS;
        }

        foreach ($drifts as $reason) {
            $this->warn('Drift: '.$reason);
        }

        $this->error(count($drifts).' legal document(s) have drifted from their published version.');

        return self::FAILURE;
    }

    /**
     * @return list<string>
     */
    private function configuredLocales(): array
    {
        $locales = config('legal-consent.locales');

        if (is_array($locales)) {
            $strings = array_values(array_filter($locales, is_string(...)));

            if ($strings !== []) {
                return $strings;
            }
        }

        $default = config('legal-consent.default_locale', 'de');

        return [is_string($default) ? $default : 'de'];
    }
}
