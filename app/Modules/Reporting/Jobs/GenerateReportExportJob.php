<?php

namespace App\Modules\Reporting\Jobs;

use App\Modules\Reporting\Enums\ExportStatus;
use App\Modules\Reporting\Models\ReportExport;
use App\Modules\Reporting\Services\ReportFileWriter;
use App\Modules\Reporting\Services\ReportRegistry;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Queue\QueueNames;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Builds one requested report file (EDU-021).
 *
 * On the `exports` queue and the long-running connection, because that is the
 * whole reason this is a job: a ledger PDF over a year of orders takes minutes,
 * and an inline download dies at the web server long before the query finishes.
 * See docs/queues.md for why `exports` needs its own connection.
 *
 * The tenant is carried in the row and re-bound here: every query the report
 * runs is tenant-scoped by global scope, and a worker has no request to inherit
 * a tenant from. Without this the report would come back empty rather than
 * wrong, which is a small mercy but still a bug.
 */
class GenerateReportExportJob implements ShouldQueue
{
    use Queueable;

    /** One retry: a failed export should reach the teacher quickly, not in half an hour. */
    public int $tries = 2;

    public function __construct(public int $exportId)
    {
        $this->onConnection(QueueNames::LongRunningConnection);
        $this->onQueue(QueueNames::Exports);
    }

    public function handle(
        ReportRegistry $registry,
        ReportFileWriter $writer,
        TenantContext $context,
    ): void {
        $export = ReportExport::query()->find($this->exportId);

        if ($export === null || $export->status !== ExportStatus::Queued) {
            // Deleted, or already picked up: a re-delivery must not rebuild a
            // file someone may already have downloaded.
            return;
        }

        $export->update(['status' => ExportStatus::Processing, 'started_at' => now()]);

        $tenant = $export->tenant_id === null
            ? null
            : Tenant::withoutGlobalScopes()->find($export->tenant_id);

        if ($tenant !== null) {
            $context->setTenant($tenant);
        }

        try {
            $built = $writer->write(
                $registry->for($export),
                $export->format,
                $export->locale,
                $tenant?->name ?? config('app.name'),
            );

            $path = $this->path($export);
            Storage::disk((string) config('reports.disk'))->put($path, $built['contents']);

            $export->update([
                'status' => ExportStatus::Ready,
                'file_path' => $path,
                'file_size' => strlen($built['contents']),
                'row_count' => $built['rows'],
                'finished_at' => now(),
                'expires_at' => now()->addDays((int) config('reports.retention_days')),
            ]);
        } catch (Throwable $e) {
            // The row is the only place a teacher can learn that their export
            // failed, so the reason goes there — trimmed, because a stack trace
            // in a UI field helps nobody.
            $export->update([
                'status' => ExportStatus::Failed,
                'failure_reason' => mb_substr($e->getMessage(), 0, 500),
                'finished_at' => now(),
            ]);

            Log::error('[reports] export failed', [
                'export_id' => $export->id,
                'report' => $export->report->value,
                'format' => $export->format->value,
                'exception' => $e,
            ]);

            throw $e;
        }
    }

    /** Per-tenant directory; platform exports live under `platform`. */
    private function path(ReportExport $export): string
    {
        $scope = $export->tenant_id === null ? 'platform' : (string) $export->tenant_id;

        return "reports/{$scope}/{$export->report->value}-{$export->uuid}.{$export->format->extension()}";
    }

    /**
     * Both tries exhausted: the row must not be left saying `processing` forever,
     * or the client polls a status that will never change.
     */
    public function failed(Throwable $e): void
    {
        ReportExport::query()->where('id', $this->exportId)->update([
            'status' => ExportStatus::Failed->value,
            'failure_reason' => mb_substr($e->getMessage(), 0, 500),
            'finished_at' => now(),
        ]);
    }
}
