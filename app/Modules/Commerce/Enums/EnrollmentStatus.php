<?php

namespace App\Modules\Commerce\Enums;

enum EnrollmentStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
