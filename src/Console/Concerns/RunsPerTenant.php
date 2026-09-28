<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console\Concerns;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pushery\LegalConsent\Support\TenantContext;

/**
 * The `--tenant` option of a command that works on documents, and the tenants it works in.
 *
 * The console resolves no tenant, so with tenancy on a command reads and writes the shared bucket
 * unless it is told otherwise. A command that checks or repairs what is published works in every
 * tenant, the way the sweeps do; one that publishes or edits works in the tenant `--tenant` names,
 * or in the shared bucket. Without tenancy there is one set of documents, and `--tenant` is refused
 * rather than ignored, because an ignored option reads as if it had scoped the run.
 */
trait RunsPerTenant
{
    /**
     * The tenant `--tenant` names, null when it names none, or false after refusing it.
     */
    private function namedTenant(): string|false|null
    {
        $tenant = $this->option('tenant');

        if (! is_string($tenant)) {
            return null;
        }

        if (! app(TenantContext::class)->enabled()) {
            $this->error('--tenant applies only with legal-consent.tenancy.enabled; without tenancy there is one set of documents.');

            return false;
        }

        return $tenant;
    }

    /**
     * The tenants a check runs in: the named one; the one the console resolves, where something runs
     * commands per tenant; otherwise, with tenancy on, every tenant that holds a document or a
     * draft, and the shared bucket.
     *
     * @return list<string>
     */
    private function tenantsToCheck(?string $named): array
    {
        if ($named !== null) {
            return [$named];
        }

        $context = app(TenantContext::class);

        if (! $context->enabled() || $context->current() !== '') {
            return [$context->current()];
        }

        // The query builder rather than the models, because the models are scoped to the current
        // tenant, and the point is to see every one.
        $tenants = DB::table('legal_documents')->distinct()->pluck('tenant_id');

        if (Schema::hasTable('legal_drafts')) {
            $tenants = $tenants->merge(DB::table('legal_drafts')->distinct()->pluck('tenant_id'));
        }

        return array_values($tenants->push($context->current())
            ->map(static fn (mixed $tenant): string => is_scalar($tenant) ? (string) $tenant : '')
            ->unique()
            ->sort()
            ->all());
    }

    /**
     * Says so when a command that writes, with tenancy on, works in the shared bucket because the
     * console resolves no tenant and none is named.
     */
    private function noteSharedBucket(?string $named): void
    {
        $context = app(TenantContext::class);

        if ($named === null && $context->enabled() && $context->current() === '') {
            $this->comment('No tenant is resolved here and none is named with --tenant, so this works in the shared bucket.');
        }
    }

    /**
     * How a line names the tenant it is about: nothing without tenancy, `-` for the shared bucket.
     */
    private function tenantLabel(string $tenant): string
    {
        if (! app(TenantContext::class)->enabled()) {
            return '';
        }

        return ' (tenant '.($tenant === '' ? '-' : $tenant).')';
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function inTenant(?string $tenant, Closure $callback): mixed
    {
        return $tenant === null ? $callback() : app(TenantContext::class)->forTenant($tenant, $callback);
    }
}
