<?php

namespace App\Modules\Notifications\Support;

use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;

/**
 * Who on the staff side hears about a business event.
 *
 * The answer is always a PERMISSION question, never a membership-kind one (M20):
 * an assistant granted `payments.receipts.review` should hear about a new
 * receipt, and a teacher who delegated that away should not be forced to. This
 * generalises Engagement's TicketRecipients to any permission key.
 *
 * `tenant_user` is the GLOBAL membership table, so the tenant is filtered here
 * explicitly rather than relying on a scope.
 */
class StaffRecipients
{
    /**
     * Active teacher/assistant user ids in this academy holding `$permission`.
     *
     * @return list<int>
     */
    public static function withPermission(int $tenantId, string $permission): array
    {
        return TenantUser::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('role', [TenantUserRole::Teacher->value, TenantUserRole::Assistant->value])
            ->with('user')
            ->get()
            ->filter(fn (TenantUser $m): bool => $m->isActive() && $m->holdsPermission($permission))
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The academy owner(s) — for alerts that are about the account itself
     * (subscription, plan limits, a verified domain) rather than about work an
     * assistant could be delegated.
     *
     * @return list<int>
     */
    public static function owners(int $tenantId): array
    {
        return TenantUser::query()
            ->where('tenant_id', $tenantId)
            ->where('role', TenantUserRole::Teacher->value)
            ->get()
            ->filter(static fn (TenantUser $m): bool => $m->isActive())
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
