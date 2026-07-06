<?php

namespace App\Modules\Identity\Enums;

/**
 * What an OTP authorises (FR-M11-02). A verify request must present the same
 * purpose the code was issued for, so a reset code cannot complete a login.
 */
enum OtpPurpose: string
{
    case Register = 'register';
    case Login = 'login';
    case Reset = 'reset';
}
