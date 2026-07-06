<?php

namespace App\Modules\Commerce\Enums;

enum EnrollmentSource: string
{
    case Purchase = 'purchase';
    case Wallet = 'wallet';
    case Code = 'code';
    case Manual = 'manual';
    case Center = 'center';
}
