<?php

namespace App\Modules\PlatformAdmin\Http\Controllers;

use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Package;
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

        $lessons = Lesson::withoutGlobalScopes()->count();
        $packages = Package::withoutGlobalScopes()->count();

        return response()->json(['data' => [
            'teachers' => Tenant::query()->count(),
            'students' => $students,
            // Content volume across the platform. `courses` is retired (VD §7) —
            // lessons and packages are what academies actually publish now, and the
            // overview lost its content figure entirely when courses were removed.
            'lessons' => $lessons,
            'packages' => $packages,
            // Back-compat alias: the SPA's admin overview card still reads `courses`
            // (src/api/endpoints/admin.js -> courses_total). Keep it until that
            // adapter is moved onto `lessons`/`packages`, so renaming the figure
            // here does not blank the card.
            'courses' => $lessons + $packages,
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
