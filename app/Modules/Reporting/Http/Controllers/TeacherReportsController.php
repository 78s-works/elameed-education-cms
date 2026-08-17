<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Models\User;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Package;
use App\Modules\Catalog\Services\AcademicYearContext;
use App\Modules\Centers\Models\AttendanceRecord;
use App\Modules\Commerce\Enums\EnrollmentSource;
use App\Modules\Commerce\Enums\OrderStatus;
use App\Modules\Commerce\Models\Enrollment;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderItem;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\StudentProfile;
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
    public function overview(): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        // Active academic year (null when the caller sent no X-Academic-Year):
        // scopes students / orders / revenue to the students pinned to that year.
        $yearId = $this->year->hasYear() ? $this->year->id() : null;

        // The user_ids of students pinned to the active year — the scoping key for
        // the global (year-agnostic) tables (tenant_users, orders). A sub-query so
        // it composes into whereIn without loading ids. StudentProfile is
        // tenant-scoped by BelongsToTenant. No-op filter when no year is active.
        $yearStudentIds = fn () => StudentProfile::query()
            ->where('academic_year_id', $yearId)
            ->select('user_id');

        $now = now();
        $monthStart = $now->copy()->startOfMonth();
        $windowStart = $monthStart->copy()->subMonths(11); // 12 buckets incl. current

        // ── Students (global tenant_user table → explicit tenant + year filter) ─
        $studentsBase = function () use ($tenantId, $yearId, $yearStudentIds) {
            $q = TenantUser::query()
                ->where('tenant_id', $tenantId)
                ->where('role', TenantUserRole::Student->value);
            if ($yearId !== null) {
                $q->whereIn('user_id', $yearStudentIds());
            }

            return $q;
        };

        $studentsTotal = $studentsBase()->count();
        $studentsActive = $studentsBase()->where('status', MembershipStatus::Active->value)->count();
        $studentsNewMonth = $studentsBase()->where('created_at', '>=', $monthStart)->count();

        // ── Catalog / enrollments (auto tenant + year scoped via traits) ──────
        // Courses are an internal grouping container only; the sellable items are
        // lessons and packages (VD change set), so the dashboard reports those.
        $enrollmentsTotal = Enrollment::query()->count();

        // ── Sales — gross paid orders, scoped to the active year by buyer ─────
        $paidOrders = function () use ($yearId, $yearStudentIds) {
            $q = Order::query()->where('status', OrderStatus::Paid->value);
            if ($yearId !== null) {
                $q->whereIn('user_id', $yearStudentIds());
            }

            return $q;
        };

        $salesTotal = (int) $paidOrders()->sum('total_minor');
        $salesMonth = (int) $paidOrders()->where('created_at', '>=', $monthStart)->sum('total_minor');

        $ordersPaid = $paidOrders()->count();
        $grossTotal = $salesTotal;
        $avgOrderMinor = $ordersPaid > 0 ? intdiv($grossTotal, $ordersPaid) : 0;

        // ── 12-month trend series ────────────────────────────────────────────
        $revenueByMonth = $paidOrders()
            ->where('created_at', '>=', $windowStart)
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as ym, SUM(total_minor) as total")
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

        // ── Top lessons (by enrollment count, with direct-sale revenue) ──────
        // Access is per lesson (a package fans out into per-lesson grants), so an
        // enrollment's lesson_id is the real unit. Revenue is the lesson's own
        // direct sales (TYPE_LESSON); lessons reached only via a package show 0.
        $topLessonEnroll = Enrollment::query()
            ->whereNotNull('lesson_id')
            ->selectRaw('lesson_id, COUNT(*) as total')
            ->groupBy('lesson_id')->orderByDesc('total')->limit(5)
            ->pluck('total', 'lesson_id');

        $lessonTitles = Lesson::query()->whereIn('id', $topLessonEnroll->keys())->pluck('title', 'id');

        $lessonRevenue = OrderItem::query()
            ->where('item_type', OrderItem::TYPE_LESSON)
            ->whereIn('item_id', $topLessonEnroll->keys())
            ->whereHas('order', function ($q) use ($yearId, $yearStudentIds): void {
                $q->where('status', OrderStatus::Paid->value);
                if ($yearId !== null) {
                    $q->whereIn('user_id', $yearStudentIds());
                }
            })
            ->selectRaw('item_id, SUM(price_minor) as total')
            ->groupBy('item_id')->pluck('total', 'item_id');

        $topLessons = [];
        foreach ($topLessonEnroll as $lessonId => $count) {
            $topLessons[] = [
                'title' => $lessonTitles[$lessonId] ?? '—',
                'enrollments' => (int) $count,
                'revenue_minor' => (int) ($lessonRevenue[$lessonId] ?? 0),
            ];
        }

        // ── Top packages (by paid sales, with revenue; year buyer) ───────────
        $topPkg = OrderItem::query()
            ->where('item_type', OrderItem::TYPE_PACKAGE)
            ->whereHas('order', function ($q) use ($yearId, $yearStudentIds): void {
                $q->where('status', OrderStatus::Paid->value);
                if ($yearId !== null) {
                    $q->whereIn('user_id', $yearStudentIds());
                }
            })
            ->selectRaw('item_id, COUNT(*) as sales, SUM(price_minor) as revenue')
            ->groupBy('item_id')->orderByDesc('sales')->limit(5)->get();

        $pkgNames = Package::query()->whereIn('id', $topPkg->pluck('item_id'))->pluck('name', 'id');
        $topPackages = $topPkg->map(fn ($r) => [
            'title' => $pkgNames[$r->item_id] ?? '—',
            'sales' => (int) $r->sales,
            'revenue_minor' => (int) $r->revenue,
        ])->values();

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

        // ── Content library counts (auto tenant + year scoped) ───────────────
        $lessonsTotal = Lesson::query()->count();
        $packagesTotal = Package::query()->count();
        $examsTotal = Exam::query()->count();

        // ── Attendance (center check-in → online access; year-scoped by trait) ─
        $sectionAttendance = fn () => AttendanceRecord::query()->whereNotNull('center_session_id');
        $attendanceCheckins = $sectionAttendance()->count();
        $attendanceActive = (clone $sectionAttendance())
            ->where(fn ($q) => $q->whereNull('access_expires_at')->orWhere('access_expires_at', '>', $now))
            ->count();

        // ── Student channel mix (center / online / both) ──────────────────────
        $profilesBase = fn () => StudentProfile::query()
            ->when($yearId !== null, fn ($q) => $q->where('academic_year_id', $yearId));

        $byMode = $profilesBase()->selectRaw('study_mode, COUNT(*) as total')
            ->groupBy('study_mode')->pluck('total', 'study_mode');
        $studentsByMode = collect(['center', 'online', 'both'])
            ->map(fn (string $m) => ['mode' => $m, 'count' => (int) ($byMode[$m] ?? 0)])
            ->values();

        // ── Top governorates ─────────────────────────────────────────────────
        $studentsByGovernorate = $profilesBase()
            ->whereNotNull('governorate')->where('governorate', '!=', '')
            ->selectRaw('governorate, COUNT(*) as total')
            ->groupBy('governorate')->orderByDesc('total')->limit(6)
            ->pluck('total', 'governorate')
            ->map(fn ($c, $g) => ['name' => $g, 'count' => (int) $c])->values();

        // ── Enrollments by source (year-scoped by trait) ─────────────────────
        $bySource = Enrollment::query()->selectRaw('source, COUNT(*) as total')
            ->groupBy('source')->pluck('total', 'source');
        $enrollmentsBySource = collect(EnrollmentSource::cases())
            ->map(fn (EnrollmentSource $s) => ['source' => $s->value, 'count' => (int) ($bySource[$s->value] ?? 0)])
            ->filter(fn (array $r) => $r['count'] > 0)->values();

        // ── Revenue by content type (paid order items, year buyer) ───────────
        $revByType = OrderItem::query()
            ->whereHas('order', function ($q) use ($yearId, $yearStudentIds): void {
                $q->where('status', OrderStatus::Paid->value);
                if ($yearId !== null) {
                    $q->whereIn('user_id', $yearStudentIds());
                }
            })
            ->selectRaw('item_type, SUM(price_minor) as total')
            ->groupBy('item_type')->pluck('total', 'item_type');
        $revenueByType = $revByType
            ->map(fn ($t, $k) => ['type' => $k, 'amount_minor' => (int) $t])
            ->sortByDesc('amount_minor')->values();

        // ── Orders by status (year buyer) ─────────────────────────────────────
        $ordersBase = fn () => Order::query()
            ->when($yearId !== null, fn ($q) => $q->whereIn('user_id', $yearStudentIds()));
        $byStatus = $ordersBase()->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')->pluck('total', 'status');
        $ordersByStatus = collect(OrderStatus::cases())
            ->map(fn (OrderStatus $s) => ['status' => $s->value, 'count' => (int) ($byStatus[$s->value] ?? 0)])
            ->values();

        // ── Top students (by enrollment count, with spend) ───────────────────
        $topStuEnroll = Enrollment::query()
            ->whereNotNull('user_id')
            ->selectRaw('user_id, COUNT(*) as total')
            ->groupBy('user_id')->orderByDesc('total')->limit(5)
            ->pluck('total', 'user_id');
        $stuNames = User::query()->whereIn('id', $topStuEnroll->keys())->pluck('name', 'id');
        $stuSpend = Order::query()->where('status', OrderStatus::Paid->value)
            ->whereIn('user_id', $topStuEnroll->keys())
            ->selectRaw('user_id, SUM(total_minor) as total')
            ->groupBy('user_id')->pluck('total', 'user_id');
        $topStudents = [];
        foreach ($topStuEnroll as $uid => $count) {
            $topStudents[] = [
                'name' => $stuNames[$uid] ?? '—',
                'enrollments' => (int) $count,
                'spent_minor' => (int) ($stuSpend[$uid] ?? 0),
            ];
        }

        return response()->json(['data' => [
            'students_total' => $studentsTotal,
            'students_active' => $studentsActive,
            'students_new_month' => $studentsNewMonth,
            'enrollments_total' => $enrollmentsTotal,
            'lessons_total' => $lessonsTotal,
            'packages_total' => $packagesTotal,
            'exams_total' => $examsTotal,
            'attendance_checkins' => $attendanceCheckins,
            'attendance_active' => $attendanceActive,
            'sales_this_month_minor' => $salesMonth,
            'sales_total_minor' => $salesTotal,
            'orders_paid' => $ordersPaid,
            'avg_order_minor' => $avgOrderMinor,
            'series' => $series,
            'top_lessons' => $topLessons,
            'top_packages' => $topPackages,
            'recent_sales' => $recentSales,
            'students_by_mode' => $studentsByMode,
            'students_by_governorate' => $studentsByGovernorate,
            'enrollments_by_source' => $enrollmentsBySource,
            'revenue_by_type' => $revenueByType,
            'orders_by_status' => $ordersByStatus,
            'top_students' => $topStudents,
        ]]);
    }
}
