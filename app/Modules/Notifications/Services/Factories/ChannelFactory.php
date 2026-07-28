<?php

namespace App\Modules\Notifications\Services\Factories;

use App\Modules\Notifications\Contracts\NotificationChannelInterface;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Services\ChannelDispatchers\DatabaseChannel;
use App\Modules\Notifications\Services\ChannelDispatchers\EmailChannel;
use App\Modules\Notifications\Services\ChannelDispatchers\PushChannel;
use App\Modules\Notifications\Services\ChannelDispatchers\SmsChannel;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves a channel dispatcher for a given channel (doc 10 §4 step 7e). Built
 * from the container so dispatchers get their own dependencies (e.g. SmsChannel
 * → SmsSender). Unknown channel → null (engine logs and skips).
 */
class ChannelFactory
{
    public function __construct(private readonly Container $container) {}

    public function make(NotificationChannel $channel): ?NotificationChannelInterface
    {
        $class = match ($channel) {
            NotificationChannel::Database => DatabaseChannel::class,
            NotificationChannel::Sms => SmsChannel::class,
            NotificationChannel::Email => EmailChannel::class,
            NotificationChannel::Push => PushChannel::class,
        };

        return $this->container->make($class);
    }
}
