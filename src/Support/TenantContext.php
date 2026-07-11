<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Closure;

/**
 * Optional multi-tenancy (config `tenancy`). When enabled, legal documents and consents
 * are scoped to a tenant: the consuming app registers a resolver that returns the current
 * tenant id, and the package stamps + filters every document/consent by it.
 *
 * Register the resolver in a service provider's boot():
 *
 *     app(TenantContext::class)->resolveUsing(fn () => auth()->user()?->tenant_id);
 *
 * `current()` returns '' when tenancy is off, when no resolver is set, or when the resolver
 * yields nothing (a system/console context) — '' is the shared-tenant bucket, so an app that
 * never enables tenancy behaves exactly as before (every row shares '').
 */
final class TenantContext
{
    /** @var (Closure(): mixed)|null */
    private ?Closure $resolver = null;

    public function __construct(
        private readonly bool $enabled = false,
        private readonly string $column = 'tenant_id',
    ) {}

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function column(): string
    {
        return $this->column;
    }

    /**
     * @param  Closure(): mixed  $resolver
     */
    public function resolveUsing(Closure $resolver): void
    {
        $this->resolver = $resolver;
    }

    /**
     * The current tenant id as a string, or '' for the shared bucket.
     */
    public function current(): string
    {
        if (! $this->enabled || ! $this->resolver instanceof Closure) {
            return '';
        }

        $tenant = ($this->resolver)();

        return is_int($tenant) || is_string($tenant) ? (string) $tenant : '';
    }
}
