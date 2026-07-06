<?php

namespace App\Modules\Notifications\Providers;

use App\Modules\Notifications\Contracts\SmsSender;
use App\Modules\Notifications\Sms\LogSmsSender;
use Illuminate\Support\ServiceProvider;

class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SmsSender::class, function (): SmsSender {
            return match (config('sms.driver')) {
                'log' => new LogSmsSender,
                default => new LogSmsSender,
            };
        });
    }
}
