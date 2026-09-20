<?php

namespace App\Modules\Reporting\Exporters;

use App\Modules\PlatformAdmin\Services\PlatformBusinessReport;
use App\Modules\Reporting\Models\ReportExport;
use Generator;

/**
 * The admin console's business report as a file: recurring revenue per plan,
 * with the subscription counts and conversion/churn figures as the summary.
 *
 * Platform-wide, so it belongs to no academy — {@see ReportExport}
 * stores it with a null tenant and only the admin console can request or
 * download it.
 *
 * English only in practice, since the admin console is: the locale is still
 * honoured so a future Arabic console needs no change here.
 */
class PlatformReport extends TabularReport
{
    /** @var array<string, mixed>|null */
    private ?array $data = null;

    /** @param array<string, mixed> $filters */
    public function __construct(
        private readonly PlatformBusinessReport $report,
        array $filters = [],
        string $locale = 'en',
    ) {
        parent::__construct($filters, $locale);
    }

    public function title(): string
    {
        return $this->t('تقرير المنصة', 'Platform business report');
    }

    public function headers(): array
    {
        return $this->isArabic()
            ? ['الباقة', 'عدد الأكاديميات', 'الإيراد الشهري المتكرر']
            : ['Plan', 'Academies', 'MRR (EGP)'];
    }

    public function rows(): Generator
    {
        foreach ($this->data()['mrr']['by_plan'] ?? [] as $plan) {
            yield [
                (string) ($plan['plan'] ?? '—'),
                (int) ($plan['academies'] ?? 0),
                $this->pounds((int) ($plan['mrr_minor'] ?? 0)),
            ];
        }
    }

    public function totals(): array
    {
        $d = $this->data();
        $counts = $d['counts'] ?? [];

        return [
            $this->t('إجمالي الإيراد الشهري', 'Total MRR') => $this->pounds((int) ($d['mrr']['total_minor'] ?? 0)),
            $this->t('نشِط', 'Active') => (string) ($counts['active'] ?? 0),
            $this->t('تجريبي', 'Trialing') => (string) ($counts['trialing'] ?? 0),
            $this->t('متأخر السداد', 'Past due') => (string) ($counts['past_due'] ?? 0),
            $this->t('ملغي', 'Canceled') => (string) ($counts['canceled'] ?? 0),
            $this->t('تجارب تنتهي قريباً', 'Trials ending soon') => (string) count($d['trials_ending_soon'] ?? []),
            $this->t('اشتراكات متأخرة', 'Overdue subscriptions') => (string) count($d['overdue'] ?? []),
        ];
    }

    public function filterLines(): array
    {
        $days = (int) ($this->filters['period_days'] ?? 30);

        return [$this->t("نافذة التحويل: {$days} يوماً", "Conversion window: {$days} days")];
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        return $this->data ??= $this->report->build((int) ($this->filters['period_days'] ?? 30));
    }
}
