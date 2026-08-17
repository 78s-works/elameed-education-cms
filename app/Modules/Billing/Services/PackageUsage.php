<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\SubscriptionPackage;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use Illuminate\Support\Facades\DB;

/**
 * Snapshots a tenant's current usage against a package's limits (FR-M03-02), for
 * the teacher subscription view and for creation-time enforcement (PlanLimitGuard).
 * Counts use an explicit tenant_id filter so the result is correct in any
 * middleware context.
 */
class PackageUsage
{
    /**
     * @return array<string, array{limit: int|null, used: int, remaining: int|null}>
     */
    public function forTenant(int $tenantId, ?SubscriptionPackage $package): array
    {
        $out = [];

        foreach (SubscriptionPackage::LIMIT_KEYS as $key) {
            $limit = $package?->limit($key);
            $consumed = $this->usedFor($tenantId, $key);

            $out[$key] = [
                'limit' => $limit,
                'used' => $consumed,
                'remaining' => $limit === null ? null : max(0, $limit - $consumed),
            ];
        }

        return $out;
    }

    /** Current consumption of a single canonical limit key for a tenant. */
    public function usedFor(int $tenantId, string $key): int
    {
        return match ($key) {
            'max_students' => $this->countMembers($tenantId, TenantUserRole::Student),
            // `courses` retired (VD §7): the content-quota now counts standalone lessons.
            'max_courses' => $this->countLessons($tenantId),
            'max_assistants' => $this->countMembers($tenantId, TenantUserRole::Assistant),
            'storage_mb' => $this->storageMb($tenantId),
            default => 0,
        };
    }

    private function countLessons(int $tenantId): int
    {
        // Query the table directly to sidestep the BelongsToTenant global scope.
        return (int) DB::table('lessons')
            ->where('tenant_id', $tenantId)
            ->count();
    }

    private function countMembers(int $tenantId, TenantUserRole $role): int
    {
        return TenantUser::query()
            ->where('tenant_id', $tenantId)
            ->where('role', $role->value)
            ->where('status', MembershipStatus::Active->value)
            ->count();
    }

    /**
     * Megabytes of stored media for the tenant, from the per-asset byte size
     * recorded at upload. Assets uploaded before byte-tracking landed (null
     * size) count as 0 — reporting/enforcement is best-effort until the media
     * tier back-fills sizes. Rounds up so a partial MB still consumes quota.
     */
    private function storageMb(int $tenantId): int
    {
        $bytes = (int) DB::table('media_assets')
            ->where('tenant_id', $tenantId)
            ->sum('size_bytes');

        return (int) ceil($bytes / 1048576);
    }
}
