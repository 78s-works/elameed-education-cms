<?php

namespace App\Modules\Notifications\Enums;

/**
 * Domain a notification type belongs to (doc 10 §3 enumerations). Groups the
 * catalog in management UIs; drawn from current Elameed modules.
 */
enum NotificationModule: string
{
    case Courses = 'courses';
    case Units = 'units';
    case Lessons = 'lessons';
    case Exams = 'exams';
    case Billing = 'billing';
    case Packages = 'packages';
    case Qa = 'qa';
    case Media = 'media';
    case Account = 'account';
    case Domains = 'domains';
}
