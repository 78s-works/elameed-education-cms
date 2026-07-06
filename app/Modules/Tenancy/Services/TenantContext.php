<?php

namespace App\Modules\Tenancy\Services;

use App\Modules\Tenancy\Models\Tenant;

/**
 * Request-scoped holder of the resolved tenant. Bound as a scoped singleton so
 * every class that needs "who am I serving?" reads the same instance — the
 * BelongsToTenant trait, controllers, policies, and jobs. Set by ResolveTenant.
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    public function setTenant(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function tenantId(): ?int
    {
        return $this->tenant?->getKey();
    }

    public function hasTenant(): bool
    {
        return $this->tenant !== null;
    }

    /** Assert a tenant is present, returning it (for code paths that require one). */
    public function tenantOrFail(): Tenant
    {
        if ($this->tenant === null) {
            throw new \RuntimeException('No tenant resolved for the current request.');
        }

        return $this->tenant;
    }

    public function forget(): void
    {
        $this->tenant = null;
    }
}
