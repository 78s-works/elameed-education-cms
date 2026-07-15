<?php

namespace App\Modules\Media\Providers;

use App\Modules\Media\Console\MediaHealthCommand;
use App\Modules\Media\Contracts\MediaHostProvider;
use App\Modules\Media\Contracts\MediaProvider;
use Illuminate\Support\ServiceProvider;

class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Legacy local-delivery provider (existing behavior). Unchanged.
        $this->app->bind(MediaProvider::class, fn (): MediaProvider => new LocalMediaProvider);

        // Remote Media Host control-plane client. Bound regardless of MEDIA_PROVIDER
        // so it can be health-checked; it throws (never falls back) if used while
        // unconfigured. Provider SELECTION is enforced by config('media.provider').
        $this->app->singleton(MediaHostProvider::class, fn (): MediaHostProvider => new RemoteMediaProvider);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([MediaHealthCommand::class]);
        }
    }
}
