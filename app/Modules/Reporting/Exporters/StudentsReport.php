<?php

namespace App\Modules\Reporting\Exporters;

use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Commerce\Enums\OrderStatus;
use App\Modules\Commerce\Models\Enrollment;
use App\Modules\Commerce\Models\Order;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Identity\Models\TenantUser;
use Generator;

/**
 * The student roster with the figures a teacher actually chases: what they own,
 * what they paid, and when they were last seen.
 *
 * Chunked, and the per-student aggregates are pre-loaded in three grouped
 * queries rather than one query per student — a 5,000-student academy would
 * otherwise be 15,000 round-trips inside the worker.
 */
class StudentsReport extends TabularReport
{
    /** @param array<string, mixed> $filters */
    public function __construct(
        private readonly int $tenantId,
        private readonly ?int $academicYearId = null,
        array $filters = [],
        string $locale = 'ar',
    ) {
        parent::__construct($filters, $locale);
    }

    public function title(): string
    {
        return $this->t('تقرير الطلاب', 'Students report');
    }

    public function orientation(): string
    {
        return 'L';
    }

    public function headers(): array
    {
        return $this->isArabic()
            ? ['الاسم', 'الموبايل', 'كود الطالب', 'الصف', 'نظام الدراسة', 'الحالة', 'تاريخ الانضمام', 'عدد المشتريات', 'إجمالي المدفوع', 'آخر عملية شراء']
            : ['Name', 'Phone', 'Student code', 'Grade', 'Study mode', 'Status', 'Joined', 'Purchases', 'Total paid (EGP)', 'Last purchase'];
    }

    public function rows(): Generator
    {
        $years = AcademicYear::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->pluck('name', 'id');

        $memberships = TenantUser::query()
            ->where('tenant_id', $this->tenantId)
            ->where('role', TenantUserRole::Student->value)
            ->when($this->filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($this->academicYearId !== null, fn ($q) => $q->whereIn(
                'user_id',
                StudentProfile::withoutGlobalScopes()
                    ->where('tenant_id', $this->tenantId)
                    ->where('academic_year_id', $this->academicYearId)
                    ->select('user_id'),
            ))
            ->with('user')
            ->orderBy('id');

        foreach ($memberships->lazy(500) as $chunkStart => $membership) {
            // Aggregates are resolved per chunk of ids, not per student.
            unset($chunkStart);
        }

        // lazy() yields one model at a time; batch the aggregate lookups by
        // walking in explicit chunks instead so each chunk costs 3 queries.
        $memberships->chunkById(500, function ($rows) use ($years, &$out) {
            $out = $rows;
        });

        foreach ($this->chunks() as $rows) {
            $userIds = $rows->pluck('user_id')->all();

            $profiles = StudentProfile::withoutGlobalScopes()
                ->where('tenant_id', $this->tenantId)
                ->whereIn('user_id', $userIds)
                ->get(['user_id', 'academic_year_id', 'study_mode', 'student_code'])
                ->keyBy('user_id');

            $spend = Order::withoutGlobalScopes()
                ->where('tenant_id', $this->tenantId)
                ->whereIn('user_id', $userIds)
                ->where('status', OrderStatus::Paid->value)
                ->selectRaw('user_id, count(*) orders, sum(total_minor) paid, max(created_at) last_at')
                ->groupBy('user_id')
                ->get()
                ->keyBy('user_id');

            foreach ($rows as $membership) {
                $userId = (int) $membership->user_id;
                $profile = $profiles->get($userId);
                $money = $spend->get($userId);

                yield [
                    (string) ($membership->user?->name ?? '—'),
                    (string) ($membership->user?->phone ?? ''),
                    (string) ($profile?->student_code ?? ''),
                    (string) ($years[$profile?->academic_year_id] ?? ''),
                    $this->studyModeLabel($profile?->study_mode),
                    (string) ($membership->status?->value ?? $membership->status),
                    (string) ($membership->joined_at?->format('Y-m-d') ?? ''),
                    (int) ($money->orders ?? 0),
                    $this->pounds((int) ($money->paid ?? 0)),
                    (string) ($money->last_at ? substr((string) $money->last_at, 0, 10) : ''),
                ];
            }
        }
    }

    /** @return Generator<int, \Illuminate\Support\Collection> */
    private function chunks(): Generator
    {
        $lastId = 0;

        while (true) {
            $rows = TenantUser::query()
                ->where('tenant_id', $this->tenantId)
                ->where('role', TenantUserRole::Student->value)
                ->when($this->filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
                ->when($this->academicYearId !== null, fn ($q) => $q->whereIn(
                    'user_id',
                    StudentProfile::withoutGlobalScopes()
                        ->where('tenant_id', $this->tenantId)
                        ->where('academic_year_id', $this->academicYearId)
                        ->select('user_id'),
                ))
                ->where('id', '>', $lastId)
                ->with('user')
                ->orderBy('id')
                ->limit(500)
                ->get();

            if ($rows->isEmpty()) {
                return;
            }

            yield $rows;
            $lastId = (int) $rows->last()->id;
        }
    }

    public function totals(): array
    {
        $students = TenantUser::query()
            ->where('tenant_id', $this->tenantId)
            ->where('role', TenantUserRole::Student->value)
            ->count();

        $paid = (int) Order::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->where('status', OrderStatus::Paid->value)
            ->sum('total_minor');

        $owned = Enrollment::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->count();

        return [
            $this->t('عدد الطلاب', 'Students') => (string) $students,
            $this->t('إجمالي المدفوع', 'Total paid') => $this->pounds($paid),
            $this->t('عدد الاشتراكات', 'Enrollments') => (string) $owned,
        ];
    }

    public function filterLines(): array
    {
        $lines = [];

        if ($this->academicYearId !== null) {
            $year = AcademicYear::withoutGlobalScopes()->find($this->academicYearId)?->name;
            if ($year !== null) {
                $lines[] = $this->t('الصف: ', 'Grade: ').$year;
            }
        }
        if (! empty($this->filters['status'])) {
            $lines[] = $this->t('الحالة: ', 'Status: ').$this->filters['status'];
        }

        return $lines;
    }

    private function studyModeLabel(?string $mode): string
    {
        return match ($mode) {
            'center' => $this->t('سنتر', 'Center'),
            'online' => $this->t('أونلاين', 'Online'),
            'both' => $this->t('هجين', 'Hybrid'),
            default => '',
        };
    }
}
