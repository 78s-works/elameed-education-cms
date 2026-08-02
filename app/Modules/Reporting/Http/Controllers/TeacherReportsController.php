<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Catalog\Models\Course;
use App\Modules\Commerce\Enums\OrderStatus;
use App\Modules\Commerce\Models\Enrollment;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderItem;
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

    /**
     * Rich dashboard overview (M17): KPI counters, 12-month revenue /
     * enrollment / new-student trend series, top courses, and recent sales.
     * Feeds the teacher dashboard's cards + charts in a single round-trip.
     */
    public function overview(): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();

        $now = now();
        $monthStart = $now->copy()->startOfMonth();
        $windowStart = $monthStart->copy()->subMonths(11); // 12 buckets incl. current

        // ── Students (global tenant_user table → explicit tenant filter) ──────
        $studentsBase = fn () => TenantUser::query()
            ->where('tenant_id', $tenantId)
            ->where('role', TenantUserRole::Student->value);

        $studentsTotal = $studentsBase()->count();
        $studentsActive = $studentsBase()->where('status', MembershipStatus::Active->value)->count();
        $studentsNewMonth = $studentsBase()->where('created_at', '>=', $monthStart)->count();

        // ── Catalog / enrollments (auto tenant-scoped) ───────────────────────
        $coursesTotal = Course::query()->count();
        $coursesPublished = Course::query()->published()->count();
        $enrollmentsTotal = Enrollment::query()->count();

        // ── Sales ────────────────────────────────────────────────────────────
        $earnings = fn () => LedgerEntry::query()
            ->where('account', LedgerEntry::TEACHER_EARNINGS)
            ->where('direction', LedgerEntry::CREDIT);

        $salesTotal = (int) $earnings()->sum('amount_minor');
        $salesMonth = (int) $earnings()->where('created_at', '>=', $monthStart)->sum('amount_minor');

        $paidOrders = fn () => Order::query()->where('status', OrderStatus::Paid->value);
        $ordersPaid = $paidOrders()->count();
        $grossTotal = (int) $paidOrders()->sum('total_minor');
        $avgOrderMinor = $ordersPaid > 0 ? intdiv($grossTotal, $ordersPaid) : 0;

        // ── 12-month trend series ────────────────────────────────────────────
        $revenueByMonth = $earnings()
            ->where('created_at', '>=', $windowStart)
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as ym, SUM(amount_minor) as total")
            ->groupBy('ym')->pluck('total', 'ym');

        $enrollByMonth = Enrollment::query()
            ->where('created_at', '>=', $windowStart)
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as ym, COUNT(*) as total")
            ->groupBy('ym')->pluck('total', 'ym');

        $studentsByMonth = $studentsBase()
            ->where('created_at', '>=', $windowStart)
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as ym, COUNT(*) as total")
            ->groupBy('ym')->pluck('total', 'ym');

        $series = [];
        for ($i = 0; $i < 12; $i++) {
            $m = $windowStart->copy()->addMonths($i);
            $key = $m->format('Y-m');
            $series[] = [
                'key' => $key,
                'label' => $m->format('M'),
                'revenue_minor' => (int) ($revenueByMonth[$key] ?? 0),
                'enrollments' => (int) ($enrollByMonth[$key] ?? 0),
                'students' => (int) ($studentsByMonth[$key] ?? 0),
            ];
        }

        // ── Top courses (by enrollment count, with revenue) ──────────────────
        $topEnroll = Enrollment::query()
            ->whereNotNull('course_id')
            ->selectRaw('course_id, COUNT(*) as total')
            ->groupBy('course_id')->orderByDesc('total')->limit(5)
            ->pluck('total', 'course_id');

        $courseTitles = Course::query()->whereIn('id', $topEnroll->keys())->pluck('title', 'id');

        $courseRevenue = OrderItem::query()
            ->where('item_type', OrderItem::TYPE_COURSE)
            ->whereIn('item_id', $topEnroll->keys())
            ->whereHas('order', fn ($q) => $q->where('status', OrderStatus::Paid->value))
            ->selectRaw('item_id, SUM(price_minor) as total')
            ->groupBy('item_id')->pluck('total', 'item_id');

        $topCourses = [];
        foreach ($topEnroll as $courseId => $count) {
            $topCourses[] = [
                'title' => $courseTitles[$courseId] ?? '—',
                'enrollments' => (int) $count,
                'revenue_minor' => (int) ($courseRevenue[$courseId] ?? 0),
            ];
        }

        // ── Recent sales (last 8 paid orders) ────────────────────────────────
        $recentSales = $paidOrders()
            ->with(['user:id,name', 'items:id,order_id,title'])
            ->latest()->limit(8)->get()
            ->map(fn (Order $o) => [
                'student' => $o->user?->name,
                'course' => $o->items->first()?->title,
                'amount_minor' => (int) $o->total_minor,
                'at' => $o->created_at?->toIso8601String(),
            ])->values();

        return response()->json(['data' => [
            'students_total' => $studentsTotal,
            'students_active' => $studentsActive,
            'students_new_month' => $studentsNewMonth,
            'courses_total' => $coursesTotal,
            'courses_published' => $coursesPublished,
            'enrollments_total' => $enrollmentsTotal,
            'sales_this_month_minor' => $salesMonth,
            'sales_total_minor' => $salesTotal,
            'orders_paid' => $ordersPaid,
            'avg_order_minor' => $avgOrderMinor,
            'series' => $series,
            'top_courses' => $topCourses,
            'recent_sales' => $recentSales,
        ]]);
    }
}
