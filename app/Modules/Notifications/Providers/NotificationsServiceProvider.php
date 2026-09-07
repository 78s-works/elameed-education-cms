<?php

namespace App\Modules\Notifications\Providers;

use App\Modules\Notifications\Console\AnnounceLessonsCommand;
use App\Modules\Notifications\Console\SendScheduledBroadcastsCommand;
use App\Modules\Notifications\Console\SubscriptionRemindersCommand;
use App\Modules\Notifications\Contracts\SmsSender;
use App\Modules\Notifications\Services\Engine\ChannelAvailability;
use App\Modules\Notifications\Sms\ConnekioSmsSender;
use App\Modules\Notifications\Sms\LogSmsSender;
use Illuminate\Support\ServiceProvider;

class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SmsSender::class, function (): SmsSender {
            return match (config('sms.driver')) {
                'connekio' => new ConnekioSmsSender,
                'log' => new LogSmsSender,
                default => new LogSmsSender,
            };
        });

        // Scoped, not shared: the per-(tenant, channel) memo inside must not
        // outlive a request/job, or a teacher enabling SMS would keep seeing the
        // old answer on an Octane worker.
        $this->app->scoped(ChannelAvailability::class);
    }

    public function boot(): void
    {
        // Module commands live outside app/Console/Commands, so Laravel's
        // auto-discovery does not see them — register them explicitly.
        if ($this->app->runningInConsole()) {
            $this->commands([
                AnnounceLessonsCommand::class,
                SendScheduledBroadcastsCommand::class,
                SubscriptionRemindersCommand::class,
            ]);
        }
    }
}
