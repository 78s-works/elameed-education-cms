<?php

namespace App\Modules\Notifications\Providers;

use App\Modules\Notifications\Console\AnnounceLessonsCommand;
use App\Modules\Notifications\Console\SendScheduledBroadcastsCommand;
use App\Modules\Notifications\Console\SubscriptionRemindersCommand;
use App\Modules\Notifications\Contracts\OtpSender;
use App\Modules\Notifications\Contracts\SmsSender;
use App\Modules\Notifications\Services\Engine\ChannelAvailability;
use App\Modules\Notifications\Sms\ConnekioSmsSender;
use App\Modules\Notifications\Sms\LogSmsSender;
use App\Modules\Notifications\Sms\SmsOtpSender;
use App\Modules\Notifications\Sms\Zadx\ZadxClient;
use App\Modules\Notifications\Sms\Zadx\ZadxOtpSender;
use App\Modules\Notifications\Sms\Zadx\ZadxSmsSender;
use Illuminate\Support\ServiceProvider;

class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SmsSender::class, function (): SmsSender {
            return match (config('sms.driver')) {
                'zadx' => new ZadxSmsSender($this->app->make(ZadxClient::class)),
                'connekio' => new ConnekioSmsSender,
                'log' => new LogSmsSender,
                default => new LogSmsSender,
            };
        });

        // The OTP path is bound separately because gateways disagree about what
        // an OTP IS: ZADX takes the code and renders its own approved template,
        // everyone else takes a finished message. Only ZADX needs its own
        // implementation; every other driver keeps the pre-existing behaviour of
        // sending the academy's rendered wording as ordinary text.
        $this->app->bind(OtpSender::class, function (): OtpSender {
            return match (config('sms.driver')) {
                'zadx' => new ZadxOtpSender($this->app->make(ZadxClient::class)),
                default => new SmsOtpSender($this->app->make(SmsSender::class)),
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
