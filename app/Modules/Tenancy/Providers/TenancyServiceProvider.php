<?php

namespace App\Modules\Tenancy\Providers;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantDomain;
use App\Modules\Tenancy\Observers\TenantDomainObserver;
use App\Modules\Tenancy\Observers\TenantObserver;
use App\Modules\Tenancy\Services\TenantContext;
use App\Modules\Tenancy\Services\TenantDomainRegistry;
use App\Modules\Tenancy\Services\TenantSession;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: one instance per request; reset between requests under Octane.
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(TenantSession::class);

        // Stateless (cache-backed): safe to share for the whole app lifetime.
        $this->app->singleton(TenantDomainRegistry::class);
    }

    public function boot(): void
    {
        // Keep the registered-domain gate's cache in step with the registry.
        Tenant::observe(TenantObserver::class);
        TenantDomain::observe(TenantDomainObserver::class);
    }
}
