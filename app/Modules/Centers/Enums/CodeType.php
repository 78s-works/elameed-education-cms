<?php

namespace App\Modules\Centers\Enums;

/** What redeeming an activation code grants. */
enum CodeType: string
{
    case Wallet = 'wallet';   // credits the student's wallet by amount_minor
    case Course = 'course';   // enrolls the student in course_id
}
