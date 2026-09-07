<?php

namespace App\Modules\Notifications\Support;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use App\Modules\Tenancy\Services\TenantSession;
use Throwable;

/**
 * Lets a queued job run a closure as if it were serving one academy.
 *
 * A worker process has no HTTP request behind it, so `ResolveTenant` never ran:
 * `BelongsToTenant` would not scope, `tenant_id` would not auto-fill, and — on
 * Postgres — RLS would fail closed and the job would silently see zero rows. Any
 * job that touches tenant data must therefore bind the context itself, and
 * unbind afterwards so the pooled connection does not carry one academy's id
 * into the next job (02_Architecture.md §4.2).
 */
trait RunsInTenantContext
{
    /**
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    protected function inTenantContext(?int $tenantId, callable $callback): mixed
    {
        if ($tenantId === null) {
            return $callback(); // platform-level work: no tenant to bind
        }

        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null) {
            return $callback();
        }

        $context = app(TenantContext::class);
        $session = app(TenantSession::class);

        $context->setTenant($tenant);
        $session->bind($tenant->getKey());

        try {
            return $callback();
        } finally {
            try {
                $session->reset();
            } catch (Throwable) {
                // A reset failure must not mask the job's own outcome.
            }
            $context->forget();
        }
    }
}
