<?php

namespace App\Modules\Identity\Http\Controllers\Teacher\Concerns;

use App\Models\User;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;

/**
 * Shared helper for teacher endpoints acting on one of THEIR assistants (M18).
 * Guarantees the target user is an assistant of the current tenant — otherwise
 * 404 (an assistant of another academy is invisible, not "forbidden").
 */
trait ManagesTenantAssistants
{
    /** The assistant's membership row in this tenant, or 404. */
    protected function assistantOrFail(int $tenantId, User $assistant): TenantUser
    {
        $membership = TenantUser::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $assistant->getKey())
            ->where('role', TenantUserRole::Assistant->value)
            ->first();

        abort_if($membership === null, 404, 'Assistant not found in this academy.');

        return $membership;
    }
}
