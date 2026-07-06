<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Catalog\Models\Course;
use App\Modules\Commerce\Enums\OrderStatus;
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
 * explicitly by tenant_id.
 */
class TeacherReportsController
{
    public function __construct(private readonly TenantContext $context) {}

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
            'courses' => Course::query()->count(),
        ]]);
    }
}
