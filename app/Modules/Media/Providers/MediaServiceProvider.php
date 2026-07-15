<?php

namespace App\Modules\Media\Providers;

use App\Modules\Media\Console\MigrateMediaToStore;
use App\Modules\Media\Contracts\MediaProvider;
use Illuminate\Support\ServiceProvider;

class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MediaProvider::class, function (): MediaProvider {
            return match (config('media.provider')) {
                'remote', 's3' => new RemoteMediaProvider,
                default => new LocalMediaProvider,
            };
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                MigrateMediaToStore::class,
            ]);
        }
    }
}
