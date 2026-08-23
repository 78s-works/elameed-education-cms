<?php

namespace App\Support\Files\Providers;

use App\Support\Files\Console\AuditDocuments;
use App\Support\Files\Console\PruneUnattachedDocuments;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the file layer's console side: the orphan audit, and the nightly sweep
 * of uploads that never got linked to an owner.
 */
class FilesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([AuditDocuments::class, PruneUnattachedDocuments::class]);

        // Off-peak: the sweep deletes blobs, and nothing depends on it being timely.
        $this->app->booted(function (): void {
            $this->app->make(Schedule::class)->command('documents:prune')->dailyAt('03:30');
        });
    }
}
