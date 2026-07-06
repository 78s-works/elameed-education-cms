<?php

namespace App\Modules\Identity\Enums;

/**
 * Per-tenant role (PRD §6). Platform Admin is deliberately absent — it is a
 * GLOBAL role carried by users.is_platform_admin, not a tenant membership.
 */
enum TenantUserRole: string
{
    case Teacher = 'teacher';
    case Assistant = 'assistant';
    case Student = 'student';
    case Parent = 'parent';
}
