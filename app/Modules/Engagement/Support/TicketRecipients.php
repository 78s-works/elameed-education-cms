<?php

namespace App\Modules\Engagement\Support;

use App\Modules\Identity\Enums\Permission;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;

/**
 * Resolves who gets notified about a support ticket (M09/B25, VD Item 11).
 *
 * "Staff" = the tenant's teacher(s) plus any assistant granted the `support`
 * permission (M18) — i.e. exactly the people who can reach the staff ticket
 * surface. `tenant_user` is the GLOBAL membership table (not tenant-scoped), so
 * we filter by tenant_id explicitly.
 */
class TicketRecipients
{
    /**
     * Active staff user ids for a tenant that should hear about tickets.
     *
     * @return list<int>
     */
    public static function staffFor(int $tenantId): array
    {
        return TenantUser::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('role', [TenantUserRole::Teacher->value, TenantUserRole::Assistant->value])
            ->get(['user_id', 'role', 'status', 'permissions'])
            ->filter(fn (TenantUser $m): bool => $m->isActive() && $m->hasPermission(Permission::Support->value))
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
