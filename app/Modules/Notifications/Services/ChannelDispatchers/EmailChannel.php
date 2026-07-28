<?php

namespace App\Modules\Notifications\Services\ChannelDispatchers;

use App\Models\User;
use App\Modules\Notifications\Contracts\NotificationChannelInterface;
use App\Modules\Notifications\Models\NotificationEvent;
use App\Modules\Notifications\Support\ChannelResult;

/**
 * Email channel — DEFERRED STUB (doc 10 §7). Always fails until a per-tenant
 * mailer equivalent is built. Keep templates on this channel inactive: an active
 * email template writes a NotificationFailure for every recipient each dispatch.
 */
class EmailChannel implements NotificationChannelInterface
{
    public function send(
        NotificationEvent $event,
        User $user,
        string $title,
        string $body,
        array $context = [],
    ): ChannelResult {
        return ChannelResult::fail('Email channel not implemented.');
    }
}
