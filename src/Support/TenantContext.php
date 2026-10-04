<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Pushery\LegalConsent\Exceptions\UnresolvableTenant;
use Stringable;

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
 *
 * A resolver answers with an int or a string. A backed enum counts as its value and a Stringable,
 * such as a UUID object, as its string. Any other answer, a model among them, is refused with
 * {@see UnresolvableTenant}: read as no tenant, it would put every tenant into the shared bucket.
 *
 * A resolver that reads the signed-in user also yields nothing for a guest, and a registration is
 * made by one: the new account is not signed in while its consents are recorded. Those rows would
 * land in '' and the account's own tenant would never see them. Tell the package how to find a
 * subject's tenant for that case, or resolve the tenant from the request (a domain, a route
 * parameter) so a guest has one too:
 *
 *     app(TenantContext::class)->resolveSubjectUsing(fn (Model $user) => $user->tenant_id);
 *
 * The tenant column is fixed at `tenant_id`. It was briefly configurable, which it never could
 * be: every migration declares the column literally, so pointing the option anywhere else only
 * produced a "column not found" on the first read. An advertised option that can only break is
 * worse than no option.
 */
final class TenantContext
{
    /** The tenant column, as every migration declares it. */
    public const string COLUMN = 'tenant_id';

    /** @var (Closure(): mixed)|null */
    private ?Closure $resolver = null;

    /** @var (Closure(Model): mixed)|null */
    private ?Closure $subjectResolver = null;

    public function __construct(
        private readonly bool $enabled = false,
    ) {}

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @param  Closure(): mixed  $resolver
     */
    public function resolveUsing(Closure $resolver): void
    {
        $this->resolver = $resolver;
    }

    /**
     * How to tell the tenant a subject belongs to, for what is written on its behalf while nobody
     * is signed in: the registration.
     *
     * @param  Closure(Model): mixed  $resolver
     */
    public function resolveSubjectUsing(Closure $resolver): void
    {
        $this->subjectResolver = $resolver;
    }

    /**
     * The tenant $subject belongs to, or '' when tenancy is off, no subject resolver is set, or it
     * yields nothing.
     */
    public function tenantOf(Model $subject): string
    {
        if (! $this->enabled || ! $this->subjectResolver instanceof Closure) {
            return '';
        }

        return $this->idFrom(($this->subjectResolver)($subject), 'resolveSubjectUsing');
    }

    /**
     * The current tenant id as a string, or '' for the shared bucket.
     */
    public function current(): string
    {
        if (! $this->enabled || ! $this->resolver instanceof Closure) {
            return '';
        }

        return $this->idFrom(($this->resolver)(), 'resolveUsing');
    }

    /**
     * A resolver's answer as a tenant id, with null as the shared bucket.
     *
     * A model is refused although PHP counts it as Stringable: its string form is its JSON, which
     * changes whenever one of its attributes does, so it would move the tenant's rows each time.
     */
    private function idFrom(mixed $tenant, string $resolver): string
    {
        if ($tenant === null || is_int($tenant) || is_string($tenant)) {
            return (string) $tenant;
        }

        if ($tenant instanceof BackedEnum) {
            return (string) $tenant->value;
        }

        if ($tenant instanceof Stringable && ! $tenant instanceof Model) {
            return (string) $tenant;
        }

        throw UnresolvableTenant::from($tenant, $resolver);
    }

    /**
     * Run $callback with the tenant pinned to $tenantId, then restore the previous resolver.
     *
     * A cross-tenant SYSTEM sweep (scheduler/console) has no ambient tenant — there is no
     * authenticated user, so the app's resolver yields nothing and current() falls back to the
     * shared '' bucket. A row written that way would land outside the tenant it belongs to,
     * making the proof invisible to that tenant's own queries. The sweeps therefore pin the
     * tenant of the version they are processing around every write.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function forTenant(string $tenantId, Closure $callback): mixed
    {
        $previous = $this->resolver;
        $this->resolver = static fn (): string => $tenantId;

        try {
            return $callback();
        } finally {
            $this->resolver = $previous;
        }
    }
}
