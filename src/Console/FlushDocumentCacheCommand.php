<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Pushery\LegalConsent\Content\LegalSourceRenderer;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Models\LegalDraft;
use Pushery\LegalConsent\Models\Scopes\TenantScope;
use Pushery\LegalConsent\Support\DocumentMatrix;
use Pushery\LegalConsent\Support\EnforceableDocumentCache;
use Pushery\LegalConsent\Support\TenantContext;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Flush the cached, rendered legal documents. Rarely needed (the cache
 * self-invalidates on a content change), but useful after a config or driver change.
 *
 * Both caches key their entries by tenant, and a console command runs in the shared bucket. So
 * with tenancy on it flushes every tenant that has published documents or holds drafts, and the
 * shared bucket, unless `--tenant=` names one.
 */
#[AsCommand(name: 'legal-consent:cache-flush')]
final class FlushDocumentCacheCommand extends Command
{
    protected $signature = 'legal-consent:cache-flush
        {key? : Only this document key}
        {locale? : Only this locale}
        {--tenant= : Only this tenant (multi-tenant installs); every tenant by default}';

    protected $description = 'Flush the cached, rendered legal documents and the enforceable-version set.';

    public function handle(LegalSourceRenderer $manager, EnforceableDocumentCache $enforceable, TenantContext $tenancy): int
    {
        $key = $this->argument('key');
        $locale = $this->argument('locale');
        $tenants = $this->tenants($tenancy);
        $count = 0;

        foreach ($tenants as $tenant) {
            $count += $tenancy->forTenant($tenant, fn (): int => $this->flushTenant($manager, $enforceable, $key, $locale));
        }

        if ($tenancy->enabled()) {
            $named = array_map(static fn (string $tenant): string => $tenant === '' ? "'' (shared)" : $tenant, $tenants);

            $this->info("Flushed {$count} cached legal document(s) across ".count($tenants).' tenant bucket(s): '.implode(', ', $named).'.');
        } else {
            $this->info("Flushed {$count} cached legal document(s).");
        }

        return self::SUCCESS;
    }

    /**
     * Flush one tenant's entries, the tenant being pinned by the caller, and count the documents.
     */
    private function flushTenant(LegalSourceRenderer $manager, EnforceableDocumentCache $enforceable, mixed $key, mixed $locale): int
    {
        // The gate caches WHICH versions are active, invalidated by publish plus a short TTL. An
        // out-of-band `is_active` write — a manual UPDATE, a restored dump — fires no publish, so it
        // shows only when that TTL runs out, and this command is the documented way to show it at
        // once, so it must clear that set too. Flush every locale even when one was named: the
        // enforceable set is a global fact, and a half-flushed gate is worse than a fully cold one.
        //
        // The published locales come from `legal_documents`, and `optimize:clear` runs this
        // command where there may be no such table yet, before the first `migrate` of a fresh
        // install, or no database at all, in a build step. An exception there failed the
        // framework's command and every task after this one. The declared locales are flushed all
        // the same, and the run says what it could not read.
        try {
            $enforceable->flushAll();
        } catch (QueryException $e) {
            $enforceable->flushDeclared();

            $this->warn('The published locales could not be read from `legal_documents`, so only the configured locales were flushed: '.$e->getMessage());
        }

        // `DocumentMatrix::keys()` rather than `array_keys()`, and it takes nothing away from this
        // command: the matrix drops only an INT key whose definition is not an array — the
        // list-config case, where `0` and `1` are bare names and not documents. A document whose
        // key merely LOOKS like a number, `'2024' => [...]`, has a definition and is kept, which is
        // the support this command already had.
        $keys = is_string($key) ? [$key] : DocumentMatrix::keys();
        $locales = is_string($locale) ? [$locale] : DocumentMatrix::locales();

        $count = 0;

        foreach ($keys as $documentKey) {
            foreach ($locales as $documentLocale) {
                $manager->forget((string) $documentKey, $documentLocale);
                $count++;
            }
        }

        return $count;
    }

    /**
     * The tenant buckets to flush: the one `--tenant=` names, or every tenant with published
     * documents or drafts plus the shared bucket. With tenancy off every entry lives in the shared
     * bucket, whatever the tables say.
     *
     * @return list<string>
     */
    private function tenants(TenantContext $tenancy): array
    {
        $named = $this->option('tenant');

        if (is_string($named)) {
            return [$named];
        }

        if (! $tenancy->enabled()) {
            return [''];
        }

        try {
            $found = LegalDocument::model()::query()->withoutGlobalScope(TenantScope::class)->distinct()->pluck(TenantContext::COLUMN)
                ->merge(LegalDraft::model()::query()->withoutGlobalScope(TenantScope::class)->distinct()->pluck(TenantContext::COLUMN));
        } catch (QueryException) {
            // No tables yet: the shared bucket is the one the flush below can still reach, and
            // its own fallback says what it could not read.
            return [''];
        }

        return array_values($found
            ->map(static fn (mixed $tenant): string => is_string($tenant) || is_int($tenant) ? (string) $tenant : '')
            ->push('')
            ->unique()
            ->sort()
            ->all());
    }
}
