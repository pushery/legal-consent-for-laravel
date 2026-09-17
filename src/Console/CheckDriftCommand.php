<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Illuminate\Console\Command;
use Pushery\LegalConsent\Support\DocumentMatrix;
use Pushery\LegalConsent\Support\LegalDriftChecker;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Report (never fix) any legal document whose live source text has drifted from its
 * active published version. Meant for a daily schedule + CI; a non-zero exit forces the
 * operator to publish a new, materiality-classified version before the change takes effect.
 */
#[AsCommand(name: 'legal-consent:check-drift')]
final class CheckDriftCommand extends Command
{
    protected $signature = 'legal-consent:check-drift {key? : Only this document key} {locale? : Only this locale}';

    protected $description = 'Report legal documents whose source text has drifted from the published version.';

    public function handle(LegalDriftChecker $checker): int
    {
        $key = $this->argument('key');
        $locale = $this->argument('locale');

        // `DocumentMatrix::keys()` rather than `array_keys()`, and it takes nothing away from this
        // command: the matrix drops only an INT key whose definition is not an array — the
        // list-config case, where `0` and `1` are bare names and not documents. A document whose
        // key merely LOOKS like a number, `'2024' => [...]`, has a definition and is kept, which is
        // the support this command already had.
        $keys = is_string($key) ? [$key] : DocumentMatrix::keys();
        $locales = is_string($locale) ? [$locale] : $this->configuredLocales();

        $drifts = [];

        foreach ($keys as $documentKey) {
            foreach ($locales as $documentLocale) {
                $reason = $checker->driftFor((string) $documentKey, $documentLocale);

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
            // array_values() is EQUIVALENT under mutation, since every caller only iterates the list. Static
            // analysis needs it for the list<string> return type (measured 2026-09-14).
            $strings = array_values(array_filter($locales, is_string(...)));

            if ($strings !== []) {
                return $strings;
            }
        }

        $default = config('legal-consent.default_locale', 'de');

        return [is_string($default) ? $default : 'de'];
    }
}
