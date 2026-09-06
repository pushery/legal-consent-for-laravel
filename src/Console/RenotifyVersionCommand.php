<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Models\Scopes\TenantScope;
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
 * the audience with `dispatch-notices --dry-run`, and then runs the sweep.
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
        $version = $this->locate();

        if (! $version instanceof LegalDocument) {
            $this->error('No such version. Nothing was changed.');

            return self::FAILURE;
        }

        if ($version->notified_at === null) {
            // Not an error, and deliberately not silent: reporting success over a version that was
            // never swept would read as "repaired" for the one case where nothing was wrong.
            $this->info("{$version->key} {$version->version} ({$version->locale}) was never swept — its watermark is already clear.");

            return self::SUCCESS;
        }

        $stamped = $version->notified_at->toIso8601String();

        $version->forceFill(['notified_at' => null])->saveQuietly();

        $this->info("Cleared the notice watermark on {$version->key} {$version->version} ({$version->locale}), stamped {$stamped}.");
        $this->line('Run `legal-consent:dispatch-notices --dry-run` to see the audience before the next sweep sends anything.');

        return self::SUCCESS;
    }

    private function locate(): ?LegalDocument
    {
        $tenant = $this->option('tenant');
        $requested = $this->argument('version');

        return LegalDocument::query()
            ->withoutGlobalScope(TenantScope::class) // an operator repairs a named version, not the ambient tenant's
            ->where('key', $this->argument('key'))
            ->where('locale', $this->argument('locale'))
            ->when(is_string($tenant), fn (Builder $query): Builder => $query->where('tenant_id', $tenant))
            ->when(
                is_string($requested) && $requested !== '',
                fn (Builder $query): Builder => $query->where('version', $requested),
                fn (Builder $query): Builder => $query->where('is_active', true),
            )
            ->first();
    }
}
