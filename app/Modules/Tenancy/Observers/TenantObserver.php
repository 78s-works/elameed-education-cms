<?php

namespace App\Modules\Tenancy\Observers;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantDomainRegistry;

/**
 * Keeps the registered-domain cache in step with the tenant lifecycle. A status
 * change (activate/suspend/expire), creation, delete, or restore all alter
 * whether the tenant's hosts should be served, so every one flushes the cached
 * decisions for the tenant's hosts.
 *
 * Uses `deleting` (not `deleted`) so the domain rows are still queryable when we
 * enumerate the hosts to forget — a hard delete cascades them away, and a DB
 * cascade fires no model events.
 */
class TenantObserver
{
    public function __construct(private readonly TenantDomainRegistry $registry) {}

    public function saved(Tenant $tenant): void
    {
        $this->registry->forgetTenant($tenant);
    }

    public function deleting(Tenant $tenant): void
    {
        $this->registry->forgetTenant($tenant);
    }

    public function forceDeleting(Tenant $tenant): void
    {
        $this->registry->forgetTenant($tenant);
    }

    public function restored(Tenant $tenant): void
    {
        $this->registry->forgetTenant($tenant);
    }
}
