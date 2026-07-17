<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Pushery\LegalConsent\Models\Scopes\TenantScope;
use Pushery\LegalConsent\Support\TenantContext;

/**
 * Makes a model tenant-aware when multi-tenancy is enabled (config `tenancy`): every read is
 * confined to the current tenant via {@see TenantScope}, and a new row is stamped with the
 * current tenant id on insert. Both are NO-OPs when tenancy is off, so single-tenant apps are
 * unaffected. Laravel calls bootBelongsToTenant() automatically alongside the model's own boot.
 */
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $tenant = app(TenantContext::class);

            if ($tenant->enabled() && $model->getAttribute(TenantContext::COLUMN) === null) {
                $model->setAttribute(TenantContext::COLUMN, $tenant->current());
            }
        });
    }
}
