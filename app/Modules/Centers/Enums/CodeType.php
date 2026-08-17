<?php

namespace App\Modules\Centers\Enums;

/** What redeeming an activation code grants. */
enum CodeType: string
{
    case Wallet = 'wallet';    // credits the student's wallet by amount_minor
    case Content = 'content';  // grants access to target_type/target_id (lesson|package), VD §7
}
