<?php

namespace App\Modules\Reporting\Http\Controllers\Teacher;

use App\Modules\Catalog\Services\AcademicYearContext;
use App\Modules\Reporting\Enums\ExportFormat;
use App\Modules\Reporting\Enums\ExportStatus;
use App\Modules\Reporting\Enums\ReportType;
use App\Modules\Reporting\Http\Requests\CreateReportExportRequest;
use App\Modules\Reporting\Jobs\GenerateReportExportJob;
use App\Modules\Reporting\Models\ReportExport;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A teacher's report exports (EDU-021): request one, watch it, download it.
 *
 * Three steps rather than one download, because the work does not fit in a
 * request: a ledger over a year of orders takes minutes and an inline download
 * times out at the web server. POST queues the job and returns the row
 * immediately; the client polls until the status is ready and then downloads.
 *
 * Every route is scoped to the caller's academy, and the download re-checks it
 * — a report carries student names, phone numbers and revenue, so the file is
 * never served from a public path.
 */
class ReportExportController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AcademicYearContext $year,
    ) {}

    /** The caller's own exports, newest first. */
    public function index(Request $request): JsonResponse
    {
        $exports = ReportExport::query()
            ->forTenant((int) $this->context->tenantOrFail()->getKey())
            ->where('requested_by', $request->user()->getKey())
            ->latest('id')
            ->limit(30)
            ->get();

        return response()->json(['data' => $exports->map($this->present(...))->all()]);
    }

    public function store(CreateReportExportRequest $request): JsonResponse
    {
        $filters = (array) $request->validated('filters', []);

        // The academic year is resolved HERE, from the request context, and
        // stored with the export: the job runs in a worker where no request
        // context exists, so a year read at build time would be null.
        if ($this->year->hasYear()) {
            $filters['academic_year_id'] = (int) $this->year->id();
        }

        $export = ReportExport::create([
            'tenant_id' => (int) $this->context->tenantOrFail()->getKey(),
            'requested_by' => $request->user()->getKey(),
            'report' => $request->validated('report'),
            'format' => $request->validated('format'),
            'locale' => $request->validated('locale', $request->user()->locale ?? 'ar'),
            'filters' => $filters,
        ]);

        GenerateReportExportJob::dispatch((int) $export->id);

        return response()->json(['data' => $this->present($export)], 202);
    }

    /** Poll one export. */
    public function show(ReportExport $reportExport): JsonResponse
    {
        $this->authorizeOwnership($reportExport);

        return response()->json(['data' => $this->present($reportExport)]);
    }

    public function download(ReportExport $reportExport): Response
    {
        $this->authorizeOwnership($reportExport);

        if (! $reportExport->isDownloadable()) {
            throw new NotFoundHttpException('This export is not available for download.');
        }

        $disk = Storage::disk((string) config('reports.disk'));

        // The row can say ready while the file has been swept off disk by hand,
        // so the disk is the last word.
        if (! $disk->exists((string) $reportExport->file_path)) {
            throw new NotFoundHttpException('The exported file is no longer stored.');
        }

        return response($disk->get((string) $reportExport->file_path), 200, [
            'Content-Type' => $reportExport->format->mimeType(),
            'Content-Disposition' => 'attachment; filename="'.$reportExport->downloadName().'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** A teacher only ever sees their own academy's exports. */
    private function authorizeOwnership(ReportExport $export): void
    {
        $tenantId = (int) $this->context->tenantOrFail()->getKey();

        if ((int) $export->tenant_id !== $tenantId) {
            // 404 rather than 403: another academy's export should not be
            // confirmed to exist.
            throw new NotFoundHttpException('Export not found.');
        }
    }

    /** @return array<string, mixed> */
    private function present(ReportExport $export): array
    {
        return [
            'uuid' => $export->uuid,
            'report' => $export->report->value,
            'format' => $export->format->value,
            'status' => $export->status->value,
            'row_count' => $export->row_count,
            'file_size' => $export->file_size,
            'failure_reason' => $export->failure_reason,
            'requested_at' => $export->created_at?->toIso8601String(),
            'finished_at' => $export->finished_at?->toIso8601String(),
            'expires_at' => $export->expires_at?->toIso8601String(),
            'download_name' => $export->downloadName(),
            // The client shows a link only when this is true, so it never offers
            // a download that would 404.
            'downloadable' => $export->isDownloadable(),
        ];
    }

    /** Report types a teacher may request (the platform report is admin-only). */
    public static function allowedReports(): array
    {
        return array_map(fn (ReportType $t) => $t->value, ReportType::teacherCases());
    }

    /** Formats every report supports. */
    public static function allowedFormats(): array
    {
        return array_map(fn (ExportFormat $f) => $f->value, ExportFormat::cases());
    }

    /** Statuses a client may see (documented for the API spec). */
    public static function statuses(): array
    {
        return array_map(fn (ExportStatus $s) => $s->value, ExportStatus::cases());
    }
}
