<?php

namespace App\Modules\Notifications\Contracts;

use App\Models\User;
use App\Modules\Notifications\Models\NotificationEvent;
use App\Modules\Notifications\Support\ChannelResult;

/**
 * Contract shared by every channel dispatcher (doc 10 §7). Given the audit
 * event, the recipient, the already-rendered title/body, and a context bag,
 * deliver the message and report the outcome.
 */
interface NotificationChannelInterface
{
    /**
     * @param  array<string,mixed>  $context
     */
    public function send(
        NotificationEvent $event,
        User $user,
        string $title,
        string $body,
        array $context = [],
    ): ChannelResult;
}
