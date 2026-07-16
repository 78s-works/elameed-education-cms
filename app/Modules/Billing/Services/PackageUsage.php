<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\SubscriptionPackage;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use Illuminate\Support\Facades\DB;

/**
 * Snapshots a tenant's current usage against a package's limits (FR-M03-02), for
 * the teacher subscription view. Counts use an explicit tenant_id filter so the
 * result is correct in any middleware context. Actual enforcement (blocking a
 * create over-limit) is a separate follow-up; this only reports.
 */
class PackageUsage
{
    /**
     * @return array<string, array{limit: int|null, used: int, remaining: int|null}>
     */
    public function forTenant(int $tenantId, ?SubscriptionPackage $package): array
    {
        $used = [
            'max_students' => $this->countMembers($tenantId, TenantUserRole::Student),
            'max_courses' => $this->countCourses($tenantId),
            'max_assistants' => $this->countMembers($tenantId, TenantUserRole::Assistant),
            // Storage accounting lives in the (still-stubbed) media tier — reported
            // as a limit only until byte counting lands.
            'storage_mb' => 0,
        ];

        $out = [];

        foreach (SubscriptionPackage::LIMIT_KEYS as $key) {
            $limit = $package?->limit($key);
            $consumed = $used[$key] ?? 0;

            $out[$key] = [
                'limit' => $limit,
                'used' => $consumed,
                'remaining' => $limit === null ? null : max(0, $limit - $consumed),
            ];
        }

        return $out;
    }

    private function countCourses(int $tenantId): int
    {
        // Query the table directly to sidestep the BelongsToTenant global scope
        // while still honouring soft deletes.
        return (int) DB::table('courses')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
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
}
