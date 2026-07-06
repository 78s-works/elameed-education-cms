<?php

namespace App\Support\Traits;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to every tenant-scoped Eloquent model. Two responsibilities:
 *
 *   1. A global scope that constrains queries to the current tenant, so
 *      application code reads naturally without repeating `where tenant_id`.
 *   2. Auto-fills `tenant_id` on insert from the resolved tenant, so callers
 *      never set (or spoof) it — 06_Engineering_Guide.md §5: "Never accept
 *      tenant_id from the client."
 *
 * This is the application half of defence-in-depth; Postgres RLS (TenantRls) is
 * the database half. When no tenant is resolved the scope adds nothing and RLS
 * (which fails closed) is the sole gate — the platform-admin cross-tenant path
 * uses withoutGlobalScope('tenant') on an explicitly privileged connection.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $context = app(TenantContext::class);

            if ($context->hasTenant()) {
                $model = $builder->getModel();
                $builder->where(
                    $model->qualifyColumn($model->getTenantIdColumn()),
                    $context->tenantId(),
                );
            }
        });

        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);
            $column = $model->getTenantIdColumn();

            if ($context->hasTenant() && empty($model->getAttribute($column))) {
                $model->setAttribute($column, $context->tenantId());
            }
        });
    }

    public function getTenantIdColumn(): string
    {
        return 'tenant_id';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, $this->getTenantIdColumn());
    }
}
