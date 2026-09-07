<?php

namespace App\Modules\Notifications\Http\Requests;

use App\Modules\Notifications\Enums\BroadcastAudience;
use App\Modules\Notifications\Enums\NotificationChannel;

/**
 * The platform-admin half of a custom notification: admin → every teacher, on
 * in-app + email only. SMS is not offered here on purpose — there is no platform
 * SMS account to bill it to (each academy owns its own credentials).
 */
class AdminBroadcastRequest extends BroadcastRequest
{
    /** @return list<string> */
    protected function allowedAudiences(): array
    {
        return [BroadcastAudience::Teachers->value];
    }

    /** @return list<string> */
    protected function allowedChannels(): array
    {
        return [
            NotificationChannel::Database->value,
            NotificationChannel::Email->value,
        ];
    }
}
