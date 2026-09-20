<?php

namespace App\Modules\Reporting\Http\Controllers\Admin;

use App\Modules\Reporting\Enums\ExportFormat;
use App\Modules\Reporting\Enums\ReportType;
use App\Modules\Reporting\Jobs\GenerateReportExportJob;
use App\Modules\Reporting\Models\ReportExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The admin console's platform-business report as a file (EDU-021).
 *
 * Separate from the teacher controller rather than a flag on it, because the two
 * differ in the thing that matters: these exports belong to NO academy
 * (`tenant_id` null) and carry revenue figures across all of them. Sharing one
 * controller would mean one tenant check guarding two different answers, and the
 * teacher route is the one that must never return a platform row.
 *
 * Reached only through the `central` + `admin` group, so a teacher's own domain
 * answers 404 here even with a valid platform-admin token.
 */
class AdminReportExportController
{
    public function index(Request $request): JsonResponse
    {
        $exports = ReportExport::query()
            ->platformWide()
            ->where('requested_by', $request->user()->getKey())
            ->latest('id')
            ->limit(30)
            ->get();

        return response()->json(['data' => $exports->map($this->present(...))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Only the platform report: an academy's own reports are requested
            // from that academy's panel, under its own permission key.
            'report' => ['sometimes', Rule::in([ReportType::Platform->value])],
            'format' => ['required', Rule::enum(ExportFormat::class)],
            'locale' => ['sometimes', Rule::in(['ar', 'en'])],
            'filters' => ['sometimes', 'array'],
            // The conversion/churn window the report is built over.
            'filters.period_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ]);

        $export = ReportExport::create([
            'tenant_id' => null,
            'requested_by' => $request->user()->getKey(),
            'report' => ReportType::Platform->value,
            'format' => $validated['format'],
            'locale' => $validated['locale'] ?? 'en',
            'filters' => $validated['filters'] ?? [],
        ]);

        GenerateReportExportJob::dispatch((int) $export->id);

        return response()->json(['data' => $this->present($export)], 202);
    }

    public function show(ReportExport $reportExport): JsonResponse
    {
        $this->authorizePlatformWide($reportExport);

        return response()->json(['data' => $this->present($reportExport)]);
    }

    public function download(ReportExport $reportExport): Response
    {
        $this->authorizePlatformWide($reportExport);

        if (! $reportExport->isDownloadable()) {
            throw new NotFoundHttpException('This export is not available for download.');
        }

        $disk = Storage::disk((string) config('reports.disk'));

        if (! $disk->exists((string) $reportExport->file_path)) {
            throw new NotFoundHttpException('The exported file is no longer stored.');
        }

        return response($disk->get((string) $reportExport->file_path), 200, [
            'Content-Type' => $reportExport->format->mimeType(),
            'Content-Disposition' => 'attachment; filename="'.$reportExport->downloadName().'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * An academy's export is not the console's to read. Platform admins can see
     * everything through the console's own screens, but a report FILE was built
     * for whoever asked for it, and handing one academy's student roster to a
     * different surface is not something to do by accident.
     */
    private function authorizePlatformWide(ReportExport $export): void
    {
        if ($export->tenant_id !== null) {
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
            'downloadable' => $export->isDownloadable(),
        ];
    }
}
