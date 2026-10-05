<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Models\Scopes\TenantScope;
use Pushery\LegalConsent\Support\NoticeAttempts;
use Pushery\LegalConsent\Support\TenantContext;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Clear a version's dispatch watermark so the next sweep considers it again.
 *
 * This exists for one situation and says so: until the notice audience became mode-dependent, an
 * info-only or deemed-consent change published as a minor bump selected NO subjects, reported
 * success, and stamped `notified_at` anyway. Repairing that forward leaves a cohort behind whose
 * version will never be swept again, and a paragraph in an upgrade note is not a remedy.
 *
 * `notified_at` is one of the four columns that stay writable after publish, so clearing it is a
 * legitimate operation rather than a hole in the freeze — the content, the version and the proof
 * rows are untouched. What it does NOT do is decide anything: an operator names the version, sees
 * the audience with `dispatch-notices --dry-run`, and then runs the sweep. It also forgets the
 * notice attempts recorded for the version, so a subject the sweep had given up on, or one it
 * still took for queued, is tried again.
 *
 * The version is an ARGUMENT rather than a `--version` option on purpose: Symfony's console
 * application owns `--version` globally, so a command declaring it never sees the value. The
 * framework prints its own version instead and exits successfully, which makes the mistake look
 * like a command that ran and did nothing.
 */
#[AsCommand(name: 'legal-consent:renotify')]
final class RenotifyVersionCommand extends Command
{
    protected $signature = 'legal-consent:renotify
        {key : The document key, e.g. terms}
        {locale : The locale of the version}
        {version? : The exact version string; omit to take the active version}
        {--tenant= : The tenant the version belongs to (multi-tenant installs)}';

    protected $description = 'Clear a version\'s notice watermark so the next dispatch sweep considers it again.';

    public function handle(): int
    {
        // Every tenant that published this version matches it when no tenant is named, and taking
        // any one of them could re-open another tenant's notices while the intended one stays
        // stamped. A unique active version per (key, locale, tenant) means more than one match is
        // more than one tenant.
        $tenants = $this->matching()
            ->orderBy(TenantContext::COLUMN)
            ->pluck(TenantContext::COLUMN)
            ->map(static fn (mixed $tenant): string => is_string($tenant) || is_int($tenant) ? (string) $tenant : '')
            ->all();

        if (count($tenants) > 1) {
            $this->error(sprintf(
                '%s (%s) names a version in %d tenants: %s. Name the one to repair with --tenant=. Nothing was changed.',
                $this->stringArgument('key'),
                $this->stringArgument('locale'),
                count($tenants),
                implode(', ', array_map(static fn (string $tenant): string => $tenant === '' ? "'' (shared)" : $tenant, $tenants)),
            ));

            return self::FAILURE;
        }

        $version = $this->matching()->first();

        if (! $version instanceof LegalDocument) {
            $this->error('No such version. Nothing was changed.');

            return self::FAILURE;
        }

        $label = $this->label($version);

        // The sweep stops trying a subject whose notice failed notifications.max_attempts times,
        // and skips one whose notice is still queued. A re-notify is the repair once the cause is
        // fixed, so every subject of the version is tried again.
        $forgotten = NoticeAttempts::forgetVersion($version);

        if ($forgotten > 0) {
            $this->info("Forgot {$forgotten} recorded notice attempt(s) of {$label}, so every subject is tried again.");
        }

        if ($version->notified_at === null) {
            // Not an error, and deliberately not silent: reporting success over a version that was
            // never swept would read as "repaired" for the one case where nothing was wrong.
            $this->info("{$label} was never swept — its watermark is already clear.");

            return self::SUCCESS;
        }

        $stamped = $version->notified_at->toIso8601String();

        $version->forceFill(['notified_at' => null])->saveQuietly();

        $this->info("Cleared the notice watermark on {$label}, stamped {$stamped}.");
        $this->line('Run `legal-consent:dispatch-notices --dry-run` to see the audience before the next sweep sends anything.');

        return self::SUCCESS;
    }

    /**
     * The rows the arguments name, across tenants unless `--tenant` names one.
     *
     * @return Builder<LegalDocument>
     */
    private function matching(): Builder
    {
        $tenant = $this->option('tenant');
        $requested = $this->argument('version');

        return LegalDocument::model()::query()
            ->withoutGlobalScope(TenantScope::class) // an operator repairs a named version, not the ambient tenant's
            ->where('key', $this->argument('key'))
            ->where('locale', $this->argument('locale'))
            ->when(is_string($tenant), fn (Builder $query): Builder => $query->where('tenant_id', $tenant))
            ->when(
                is_string($requested) && $requested !== '',
                fn (Builder $query): Builder => $query->where('version', $requested),
                fn (Builder $query): Builder => $query->where('is_active', true),
            );
    }

    /** Key, version and locale, and the tenant when the version belongs to one. */
    private function label(LegalDocument $version): string
    {
        $tenant = $version->getAttribute(TenantContext::COLUMN);
        $tenant = is_string($tenant) || is_int($tenant) ? (string) $tenant : '';

        return $tenant === ''
            ? "{$version->key} {$version->version} ({$version->locale})"
            : "{$version->key} {$version->version} ({$version->locale}, tenant {$tenant})";
    }

    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? $value : '';
    }
}
