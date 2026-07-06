<?php

namespace App\Modules\Tenancy\Providers;

use App\Modules\Tenancy\Services\TenantContext;
use App\Modules\Tenancy\Services\TenantSession;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: one instance per request; reset between requests under Octane.
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(TenantSession::class);
    }
}
