<?php

namespace App\Modules\Identity\Enums;

/**
 * Lifecycle of a tenant membership. A student registration starts `pending`
 * and becomes `active` once the OTP is verified.
 */
enum MembershipStatus: string
{
    case Active = 'active';
    case Pending = 'pending';
    case Suspended = 'suspended';
}
