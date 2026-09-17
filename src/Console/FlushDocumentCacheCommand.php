<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Illuminate\Console\Command;
use Pushery\LegalConsent\Content\LegalSourceRenderer;
use Pushery\LegalConsent\Support\DocumentMatrix;
use Pushery\LegalConsent\Support\EnforceableDocumentCache;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Flush the cached, rendered legal documents. Rarely needed (the cache
 * self-invalidates on a content change), but useful after a config or driver change.
 */
#[AsCommand(name: 'legal-consent:cache-flush')]
final class FlushDocumentCacheCommand extends Command
{
    protected $signature = 'legal-consent:cache-flush {key? : Only this document key} {locale? : Only this locale}';

    protected $description = 'Flush the cached, rendered legal documents and the enforceable-version set.';

    public function handle(LegalSourceRenderer $manager, EnforceableDocumentCache $enforceable): int
    {
        $key = $this->argument('key');
        $locale = $this->argument('locale');

        // The gate caches WHICH versions are currently enforceable, invalidated by publish plus a
        // short TTL. An out-of-band `is_active` write — a manual UPDATE, a restored dump — is seen
        // by neither, and this command is the documented escape hatch for exactly that, so it must
        // clear that set too. Flush every locale even when one was named: the enforceable set is a
        // global fact, and a half-flushed gate is worse than a fully cold one.
        $enforceable->flushAll();

        // `DocumentMatrix::keys()` rather than `array_keys()`, and it takes nothing away from this
        // command: the matrix drops only an INT key whose definition is not an array — the
        // list-config case, where `0` and `1` are bare names and not documents. A document whose
        // key merely LOOKS like a number, `'2024' => [...]`, has a definition and is kept, which is
        // the support this command already had.
        $keys = is_string($key) ? [$key] : DocumentMatrix::keys();
        $locales = is_string($locale) ? [$locale] : $this->configuredLocales();

        $count = 0;

        foreach ($keys as $documentKey) {
            foreach ($locales as $documentLocale) {
                $manager->forget((string) $documentKey, $documentLocale);
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
