<?php

namespace App\Modules\Notifications\Enums;

/**
 * Lifecycle of a custom notification. `Scheduled` rows are picked up by
 * `notifications:send-scheduled`; `Sending` is the in-flight marker that keeps
 * a second worker from sending the same broadcast twice.
 */
enum BroadcastStatus: string
{
    case Scheduled = 'scheduled';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Canceled = 'canceled';

    /** Can the sender still call it off? */
    public function isCancelable(): bool
    {
        return $this === self::Scheduled;
    }
}
