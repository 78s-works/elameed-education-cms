<?php

namespace App\Modules\Reporting\Enums;

/**
 * The reports that can be exported to a file (EDU-021).
 *
 *   Sales     — the sales ledger: one row per sale, the same rows the table shows.
 *   Students  — the student roster with progress, spend and attendance summary.
 *   Overview  — the dashboard: KPI counters plus the 12-month trend series.
 *   Platform  — the admin console's cross-tenant business report.
 */
enum ReportType: string
{
    case Sales = 'sales';
    case Students = 'students';
    case Overview = 'overview';
    case Platform = 'platform';

    /** Reports a teacher may request for their own academy. */
    public static function teacherCases(): array
    {
        return [self::Sales, self::Students, self::Overview];
    }

    /** Platform-wide reports live in the admin console, above any tenant. */
    public function isPlatformWide(): bool
    {
        return $this === self::Platform;
    }
}
