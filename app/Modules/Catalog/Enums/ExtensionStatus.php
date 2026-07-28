<?php

namespace App\Modules\Catalog\Enums;

/**
 * Lifecycle of a lesson-access extension request ("Lesson Availability &
 * Extension Requests"). A student asks for more time after their window
 * expires; staff grant or deny it (subject to the per-lesson max).
 */
enum ExtensionStatus: string
{
    case Pending = 'pending';
    case Granted = 'granted';
    case Denied = 'denied';
}
