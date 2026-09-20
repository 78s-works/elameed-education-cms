<?php

namespace App\Modules\Reporting\Providers;

use App\Modules\Reporting\Console\PurgeReportExportsCommand;
use Illuminate\Support\ServiceProvider;

class ReportingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Module commands live outside app/Console/Commands, so Laravel's
        // auto-discovery does not see them — register them explicitly.
        if ($this->app->runningInConsole()) {
            $this->commands([
                PurgeReportExportsCommand::class,
            ]);
        }
    }
}
