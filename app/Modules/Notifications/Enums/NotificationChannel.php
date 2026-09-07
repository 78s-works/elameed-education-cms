<?php

namespace App\Modules\Notifications\Enums;

/**
 * Delivery mediums for the notification engine (doc 10 §3). `database`, `sms`
 * and `email` have real dispatchers; `push` is still a deferred stub that always
 * fails — keep templates on it inactive until a device-token transport is built.
 *
 * Availability is a separate question from implementation: a channel with a real
 * dispatcher can still be off for an academy (SMS before credentials are stored,
 * or an explicit kill-switch) — see ChannelAvailability.
 */
enum NotificationChannel: string
{
    case Database = 'database';
    case Sms = 'sms';
    case Email = 'email';
    case Push = 'push';

    /** Is a real dispatcher wired for this channel (vs a deferred stub)? */
    public function isImplemented(): bool
    {
        return $this !== self::Push;
    }
}
