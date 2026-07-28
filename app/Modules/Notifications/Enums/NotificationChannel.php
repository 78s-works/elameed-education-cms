<?php

namespace App\Modules\Notifications\Enums;

/**
 * Delivery mediums for the notification engine (doc 10 §3). `database` + `sms`
 * are implemented first (student alerts, OTP, MENA reach); `email` + `push` are
 * deferred stubs that always fail — keep templates on them inactive until built.
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
        return $this === self::Database || $this === self::Sms;
    }
}
