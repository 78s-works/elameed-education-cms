<?php

namespace App\Modules\Identity\Http\Controllers\Teacher\Concerns;

use App\Models\User;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;

/**
 * Shared helpers for teacher endpoints that act on one of THEIR students.
 * Guarantees the target user is actually a student of the current tenant —
 * otherwise 404 (so a user from another academy is invisible, not "forbidden").
 */
trait ManagesTenantStudents
{
    /** The student's membership row in this tenant, or 404. */
    protected function membershipOrFail(int $tenantId, User $student): TenantUser
    {
        $membership = TenantUser::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $student->getKey())
            ->where('role', TenantUserRole::Student->value)
            ->first();

        abort_if($membership === null, 404, 'Student not found in this academy.');

        return $membership;
    }
}
