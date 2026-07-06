<?php

namespace App\Modules\Tenancy\Enums;

/**
 * Tenant lifecycle status (FR-M01-02). A suspended/expired tenant is blocked
 * from teacher actions and its public site shows an appropriate state
 * (FR-M01-05); those checks live at the auth/policy layer, this enum is the
 * source of truth for the value.
 */
enum TenantStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case UnderReview = 'under_review';
    case Expired = 'expired';

    /** May the tenant's teacher-side actions be performed? */
    public function isOperational(): bool
    {
        return $this === self::Active;
    }
}
