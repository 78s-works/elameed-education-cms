<?php

namespace App\Modules\Centers\Enums;

enum CodeStatus: string
{
    case Active = 'active';
    case Redeemed = 'redeemed';
    case Disabled = 'disabled';
}
