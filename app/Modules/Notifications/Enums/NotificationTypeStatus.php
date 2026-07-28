<?php

namespace App\Modules\Notifications\Enums;

/**
 * Lifecycle gate for a notification type (doc 10 §8). `draft` → `planning` →
 * `ready`. Only `ready` is live: the engine bails BEFORE recording an event for
 * non-ready types, and tenant screens list/override only `ready` types.
 */
enum NotificationTypeStatus: string
{
    case Draft = 'draft';
    case Planning = 'planning';
    case Ready = 'ready';

    public function isLive(): bool
    {
        return $this === self::Ready;
    }
}
