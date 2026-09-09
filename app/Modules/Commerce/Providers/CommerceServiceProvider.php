<?php

namespace App\Modules\Commerce\Providers;

use App\Modules\Commerce\Console\ReconcileFawryPaymentsCommand;
use Illuminate\Support\ServiceProvider;

class CommerceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Module commands live outside app/Console/Commands, so Laravel's
        // auto-discovery does not see them — register them explicitly.
        if ($this->app->runningInConsole()) {
            $this->commands([
                ReconcileFawryPaymentsCommand::class,
            ]);
        }
    }
}
