<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Illuminate\Console\Command;
use Pushery\LegalConsent\Content\LegalDocumentManager;

/**
 * Flush the cached, rendered legal documents. Rarely needed (the cache
 * self-invalidates on a content change), but useful after a config or driver change.
 */
final class FlushDocumentCacheCommand extends Command
{
    protected $signature = 'legal-consent:cache-flush {key? : Only this document key} {locale? : Only this locale}';

    protected $description = 'Flush the cached, rendered legal documents.';

    public function handle(LegalDocumentManager $manager): int
    {
        $key = $this->argument('key');
        $locale = $this->argument('locale');

        $keys = is_string($key) ? [$key] : array_keys((array) config('legal-consent.documents', []));
        $locales = is_string($locale) ? [$locale] : $this->configuredLocales();

        $count = 0;

        foreach ($keys as $documentKey) {
            foreach ($locales as $documentLocale) {
                $manager->forget((string) $documentKey, (string) $documentLocale);
                $count++;
            }
        }

        $this->info("Flushed {$count} cached legal document(s).");

        return self::SUCCESS;
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
