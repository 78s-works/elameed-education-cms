<?php

namespace App\Modules\Notifications\Services\ChannelDispatchers;

use App\Models\User;
use App\Modules\Notifications\Contracts\NotificationChannelInterface;
use App\Modules\Notifications\Models\NotificationEvent;
use App\Modules\Notifications\Support\ChannelResult;

/**
 * Push channel — DEFERRED STUB (doc 10 §7). Always fails until FCM/web-push is
 * built. Keep templates on this channel inactive (see EmailChannel note).
 */
class PushChannel implements NotificationChannelInterface
{
    public function send(
        NotificationEvent $event,
        User $user,
        string $title,
        string $body,
        array $context = [],
    ): ChannelResult {
        return ChannelResult::fail('Push channel not implemented.');
    }
}
