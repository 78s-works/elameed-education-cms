<?php

namespace App\Modules\Notifications\Enums;

/**
 * Priority of a notification type (doc 10 §3). Advisory — conveys urgency to
 * recipients and management UIs; does not change dispatch behaviour.
 */
enum NotificationSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
}
