<?php

namespace App\Modules\Reporting\Exporters;

use App\Modules\Reporting\Services\SalesLedgerExporter;
use App\Modules\Reporting\Services\SalesLedgerQuery;
use Generator;

/**
 * The sales ledger as a file. Rows and columns come from the SAME mapping the
 * inline CSV/XLSX download uses ({@see SalesLedgerExporter::lines()}), so the
 * PDF cannot disagree with the spreadsheet about a discount or a refund — which
 * is the whole reason a teacher exports a ledger in the first place.
 *
 * Wide table: landscape, or thirteen columns become unreadable slivers.
 */
class SalesLedgerReport extends TabularReport
{
    /** @param array<string, mixed> $filters */
    public function __construct(
        private readonly SalesLedgerQuery $query,
        private readonly SalesLedgerExporter $exporter,
        array $filters = [],
        string $locale = 'ar',
    ) {
        parent::__construct($filters, $locale);
    }

    public function title(): string
    {
        return $this->t('تقرير المبيعات', 'Sales report');
    }

    public function orientation(): string
    {
        return 'L';
    }

    public function headers(): array
    {
        if (! $this->isArabic()) {
            return SalesLedgerExporter::HEADERS;
        }

        return [
            'التاريخ والوقت',
            'الطالب',
            'الموبايل',
            'نوع العنصر',
            'العنصر',
            'السعر الأصلي',
            'الخصم',
            'المدفوع',
            'المُرَد',
            'الكوبون',
            'طريقة الدفع',
            'الحالة',
            'المرجع',
        ];
    }

    public function rows(): Generator
    {
        foreach ($this->query->stream($this->filters) as $row) {
            foreach ($this->exporter->lines($row) as $line) {
                yield $line;
            }
        }
    }

    public function totals(): array
    {
        $totals = $this->query->totals($this->filters);

        return [
            $this->t('عدد العمليات', 'Transactions') => (string) $totals['transactions'],
            $this->t('المُحصَّل', 'Collected') => $this->pounds((int) $totals['collected_minor']),
            $this->t('المُرَد', 'Refunded') => $this->pounds((int) $totals['refunded_minor']),
            $this->t('صافي المبيعات', 'Net sales') => $this->pounds((int) $totals['net_sales_minor']),
            $this->t('معلّق', 'Pending') => $this->pounds((int) $totals['pending_minor']),
        ];
    }

    public function filterLines(): array
    {
        $lines = [];

        if (! empty($this->filters['date_from']) || ! empty($this->filters['date_to'])) {
            $from = $this->filters['date_from'] ?? '…';
            $to = $this->filters['date_to'] ?? '…';
            $lines[] = $this->t("من {$from} إلى {$to}", "From {$from} to {$to}");
        }
        // Both arrive as arrays from the ledger screen's multi-selects.
        if (! empty($this->filters['status'])) {
            $lines[] = $this->t('الحالة: ', 'Status: ').implode(', ', (array) $this->filters['status']);
        }
        if (! empty($this->filters['method'])) {
            $lines[] = $this->t('طريقة الدفع: ', 'Method: ').implode(', ', (array) $this->filters['method']);
        }
        if (! empty($this->filters['q'])) {
            $lines[] = $this->t('بحث: ', 'Search: ').(string) $this->filters['q'];
        }

        return $lines;
    }
}
