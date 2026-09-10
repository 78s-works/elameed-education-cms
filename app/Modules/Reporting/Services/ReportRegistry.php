<?php

namespace App\Modules\Reporting\Services;

use App\Modules\PlatformAdmin\Services\PlatformBusinessReport;
use App\Modules\Reporting\Enums\ReportType;
use App\Modules\Reporting\Exporters\OverviewReport;
use App\Modules\Reporting\Exporters\PlatformReport;
use App\Modules\Reporting\Exporters\SalesLedgerReport;
use App\Modules\Reporting\Exporters\StudentsReport;
use App\Modules\Reporting\Exporters\TabularReport;
use App\Modules\Reporting\Models\ReportExport;
use RuntimeException;

/**
 * Builds the right report object for a requested export.
 *
 * One place that knows which report each type means, so the job stays free of
 * report-specific wiring and adding a report is one arm of one match.
 */
class ReportRegistry
{
    public function __construct(
        private readonly SalesLedgerQuery $sales,
        private readonly SalesLedgerExporter $salesExporter,
        private readonly TeacherOverviewQuery $overview,
        private readonly PlatformBusinessReport $platform,
    ) {}

    public function for(ReportExport $export): TabularReport
    {
        $filters = $export->filters ?? [];
        $locale = $export->locale;

        return match ($export->report) {
            ReportType::Sales => new SalesLedgerReport($this->sales, $this->salesExporter, $filters, $locale),
            ReportType::Students => new StudentsReport(
                (int) $export->tenant_id,
                $this->academicYearId($filters),
                $filters,
                $locale,
            ),
            ReportType::Overview => new OverviewReport(
                $this->overview,
                (int) $export->tenant_id,
                $this->academicYearId($filters),
                $filters,
                $locale,
            ),
            ReportType::Platform => new PlatformReport($this->platform, $filters, $locale),
        };
    }

    /**
     * The year an export was requested under, resolved at REQUEST time and stored
     * in the filters — not read from context here, because the job runs in a
     * worker where no request context exists.
     *
     * @param  array<string, mixed>  $filters
     */
    private function academicYearId(array $filters): ?int
    {
        $id = $filters['academic_year_id'] ?? null;

        if ($id !== null && ! is_numeric($id)) {
            throw new RuntimeException('academic_year_id in export filters must be numeric.');
        }

        return $id === null ? null : (int) $id;
    }
}
