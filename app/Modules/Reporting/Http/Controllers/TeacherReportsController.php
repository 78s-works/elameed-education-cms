<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Package;
use App\Modules\Catalog\Services\AcademicYearContext;
use App\Modules\Commerce\Enums\OrderStatus;
use App\Modules\Commerce\Models\Enrollment;
use App\Modules\Commerce\Models\Order;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Services\TenantContext;
use App\Modules\Wallet\Models\LedgerEntry;
use Illuminate\Http\JsonResponse;

/**
 * Teacher analytics basics (M17, P1). All queries are tenant-scoped (the
 * teacher's own academy). tenant_user is a global table, so it's filtered
 * explicitly by tenant_id; every other model carries BelongsToTenant and is
 * auto-scoped to the resolved tenant on the teacher host.
 *
 * The dashboard {@see overview} is additionally scoped to the ACTIVE ACADEMIC
 * YEAR when one is set (X-Academic-Year). Year-scoped content models (Lesson,
 * Package, Enrollment) filter themselves via BelongsToAcademicYear; students,
 * orders and
 * revenue are keyed on the student's pinned year (student_profiles.academic_year_id)
 * — a paid order counts toward the year its buyer belongs to.
 */
class TeacherReportsController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AcademicYearContext $year,
    ) {}

    public function sales(): JsonResponse
    {
        // teacher_earnings credits are already tenant-scoped by BelongsToTenant.
        $earnings = (int) LedgerEntry::query()
            ->where('account', LedgerEntry::TEACHER_EARNINGS)
            ->where('direction', LedgerEntry::CREDIT)
            ->sum('amount_minor');

        $paidOrders = Order::query()->where('status', OrderStatus::Paid->value);

        return response()->json(['data' => [
            'earnings_minor' => $earnings,
            'gross_minor' => (int) (clone $paidOrders)->sum('total_minor'),
            'orders_paid' => (clone $paidOrders)->count(),
        ]]);
    }

    public function students(): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();

        $students = TenantUser::query()
            ->where('tenant_id', $tenantId)
            ->where('role', TenantUserRole::Student->value)
            ->where('status', MembershipStatus::Active->value)
            ->count();

        return response()->json(['data' => [
            'students' => $students,
            'lessons' => Lesson::query()->count(),
        ]]);
    }

    /**
     * Rich dashboard overview (M17): KPI counters, 12-month revenue /
     * enrollment / new-student trend series, top courses, and recent sales.
     * Feeds the teacher dashboard's cards + charts in a single round-trip.
     */
    public function overview(TeacherOverviewQuery $overview): JsonResponse
    {
        return response()->json(['data' => $overview->build(
            (int) $this->context->tenantOrFail()->getKey(),
            $this->year->hasYear() ? (int) $this->year->id() : null,
        )]);
    }
}
