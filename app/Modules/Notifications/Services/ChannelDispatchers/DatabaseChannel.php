<?php

namespace App\Modules\Notifications\Services\ChannelDispatchers;

use App\Models\User;
use App\Modules\Notifications\Contracts\NotificationChannelInterface;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Models\NotificationEvent;
use App\Modules\Notifications\Models\NotificationLog;
use App\Modules\Notifications\Models\NotificationMessage;
use App\Modules\Notifications\Support\ChannelResult;

/**
 * In-app inbox channel (doc 10 §7). Creates an unread `NotificationMessage` plus
 * a `sent` `NotificationLog`. tenant_id is taken from the event explicitly so it
 * works from queue/webhook contexts where no tenant global scope is resolved
 * (mirrors the legacy NotificationService::inApp pattern).
 */
class DatabaseChannel implements NotificationChannelInterface
{
    public function send(
        NotificationEvent $event,
        User $user,
        string $title,
        string $body,
        array $context = [],
    ): ChannelResult {
        $message = new NotificationMessage([
            'notification_event_id' => $event->getKey(),
            'user_id' => $user->getKey(),
            'channel' => NotificationChannel::Database->value,
            'title' => $title,
            'body' => $body,
            'is_read' => false,
        ]);
        $message->tenant_id = $event->tenant_id;
        $message->save();

        NotificationLog::create([
            'notification_id' => $message->getKey(),
            'status' => 'sent',
            'metadata' => null,
        ]);

        return ChannelResult::ok(['notification_id' => $message->getKey()]);
    }
}
