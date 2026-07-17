<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Pushery\LegalConsent\Support\TenantContext;

/**
 * Global scope that confines every read of a tenant-aware model to the current tenant when
 * multi-tenancy is enabled (config `tenancy`). A NO-OP when tenancy is off, so the default
 * (single-tenant) behaviour and its queries are byte-for-byte unchanged. Admin sweeps that
 * must cross tenants (prune, the notice dispatch) opt out with `withoutGlobalScope(...)`.
 *
 * @implements Scope<Model>
 */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = app(TenantContext::class);

        if (! $tenant->enabled()) {
            return;
        }

        // Operate on the underlying query builder — a qualified string column is fine there,
        // whereas the Eloquent builder's model-aware where() rejects it on a generic model.
        $builder->getQuery()->where($model->getTable().'.'.TenantContext::COLUMN, $tenant->current());
    }
}
