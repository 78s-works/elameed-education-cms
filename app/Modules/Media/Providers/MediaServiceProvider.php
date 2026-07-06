<?php

namespace App\Modules\Media\Providers;

use App\Modules\Media\Contracts\MediaProvider;
use Illuminate\Support\ServiceProvider;

class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MediaProvider::class, function (): MediaProvider {
            return match (config('media.provider')) {
                'local' => new LocalMediaProvider,
                default => new LocalMediaProvider,
            };
        });
    }
}
