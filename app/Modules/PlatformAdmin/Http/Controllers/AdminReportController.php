<?php

namespace App\Modules\PlatformAdmin\Http\Controllers;

use App\Modules\Catalog\Models\Course;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Wallet\Models\LedgerEntry;
use Illuminate\Http\JsonResponse;

/**
 * GET /admin/reports/overview (FR-M17-01) — cross-tenant totals. Trivial in the
 * shared-DB model (one query each); all queries drop tenant scoping explicitly.
 */
class AdminReportController
{
    public function overview(): JsonResponse
    {
        $students = TenantUser::query()
            ->where('role', TenantUserRole::Student->value)
            ->distinct()
            ->count('user_id');

        return response()->json(['data' => [
            'teachers' => Tenant::query()->count(),
            'students' => $students,
            'courses' => Course::withoutGlobalScopes()->count(),
            'gross_earnings_minor' => (int) LedgerEntry::withoutGlobalScopes()
                ->where('account', LedgerEntry::TEACHER_EARNINGS)
                ->where('direction', LedgerEntry::CREDIT)
                ->sum('amount_minor'),
            'tenants_by_status' => Tenant::query()
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status'),
        ]]);
    }
}
