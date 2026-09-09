<?php

namespace App\Modules\Reporting\Exporters;

use App\Modules\Reporting\Services\TeacherOverviewQuery;
use Generator;

/**
 * The dashboard overview as a file.
 *
 * The table is the 12-month series — the thing that only makes sense as rows —
 * and the KPI counters become the summary block underneath. Reading it top to
 * bottom answers the same two questions the dashboard does: how the year has
 * gone month by month, and where it stands today.
 *
 * Figures come from {@see TeacherOverviewQuery}, the same builder the dashboard
 * endpoint uses, so the exported file cannot disagree with the screen it was
 * exported from.
 */
class OverviewReport extends TabularReport
{
    /** @var array<string, mixed>|null */
    private ?array $data = null;

    /** @param array<string, mixed> $filters */
    public function __construct(
        private readonly TeacherOverviewQuery $query,
        private readonly int $tenantId,
        private readonly ?int $academicYearId = null,
        array $filters = [],
        string $locale = 'ar',
    ) {
        parent::__construct($filters, $locale);
    }

    public function title(): string
    {
        return $this->t('تقرير النظرة العامة', 'Overview report');
    }

    public function headers(): array
    {
        return $this->isArabic()
            ? ['الشهر', 'الإيراد', 'الاشتراكات', 'طلاب جدد']
            : ['Month', 'Revenue (EGP)', 'Enrollments', 'New students'];
    }

    public function rows(): Generator
    {
        foreach ($this->data()['series'] ?? [] as $month) {
            yield [
                (string) ($month['label'] ?? $month['key'] ?? ''),
                $this->pounds((int) ($month['revenue_minor'] ?? 0)),
                (int) ($month['enrollments'] ?? 0),
                (int) ($month['students'] ?? 0),
            ];
        }
    }

    public function totals(): array
    {
        $d = $this->data();

        return [
            $this->t('إجمالي الطلاب', 'Students') => (string) ($d['students_total'] ?? 0),
            $this->t('طلاب نشطون', 'Active students') => (string) ($d['students_active'] ?? 0),
            $this->t('طلاب جدد هذا الشهر', 'New students this month') => (string) ($d['students_new_month'] ?? 0),
            $this->t('عدد الاشتراكات', 'Enrollments') => (string) ($d['enrollments_total'] ?? 0),
            $this->t('الدروس', 'Lessons') => (string) ($d['lessons_total'] ?? 0),
            $this->t('الباقات', 'Packages') => (string) ($d['packages_total'] ?? 0),
            $this->t('مبيعات هذا الشهر', 'Sales this month') => $this->pounds((int) ($d['sales_this_month_minor'] ?? 0)),
            $this->t('إجمالي المبيعات', 'Sales total') => $this->pounds((int) ($d['sales_total_minor'] ?? 0)),
            $this->t('طلبات مدفوعة', 'Paid orders') => (string) ($d['orders_paid'] ?? 0),
            $this->t('متوسط الطلب', 'Average order') => $this->pounds((int) ($d['avg_order_minor'] ?? 0)),
        ];
    }

    public function filterLines(): array
    {
        return $this->academicYearId === null
            ? [$this->t('كل الأعوام الدراسية', 'All academic years')]
            : [];
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        // One build per export: rows() and totals() are both called, and the
        // aggregation is a dozen queries.
        return $this->data ??= $this->query->build($this->tenantId, $this->academicYearId);
    }
}
