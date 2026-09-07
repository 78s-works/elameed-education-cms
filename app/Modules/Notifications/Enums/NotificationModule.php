<?php

namespace App\Modules\Notifications\Enums;

/**
 * Domain a notification type belongs to (doc 10 §3 enumerations). Groups the
 * catalog in management UIs; drawn from current Elameed modules.
 */
enum NotificationModule: string
{
    case Courses = 'courses';
    case Lessons = 'lessons';
    case Exams = 'exams';
    case Billing = 'billing';
    case Packages = 'packages';
    case Qa = 'qa';
    case Media = 'media';
    case Account = 'account';
    case Domains = 'domains';
    case Support = 'support';
    /** Manual receipts, orders and wallet movements the student/teacher sees. */
    case Payments = 'payments';
    /** On-premise (center) attendance, printed grades and activation codes. */
    case Center = 'center';
    /** Free-text messages a human composed — see NotificationBroadcast. */
    case Custom = 'custom';
}
