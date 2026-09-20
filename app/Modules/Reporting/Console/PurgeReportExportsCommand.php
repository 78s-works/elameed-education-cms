<?php

namespace App\Modules\Reporting\Console;

use App\Modules\Reporting\Enums\ExportStatus;
use App\Modules\Reporting\Models\ReportExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes report files whose retention window has passed (EDU-021).
 *
 * A report is a snapshot: keeping it forever means stale revenue figures
 * circulating as current, and a growing pile of student names and phone numbers
 * on disk with no remaining reason to be there.
 *
 * The ROW survives as `expired` rather than being deleted, so a teacher looking
 * for last week's ledger is told the file is gone instead of being shown a list
 * that pretends the export never happened.
 */
class PurgeReportExportsCommand extends Command
{
    protected $signature = 'reports:purge-exports {--dry-run : List what would be purged without deleting}';

    protected $description = 'Delete generated report files past their retention window';

    public function handle(): int
    {
        $disk = Storage::disk((string) config('reports.disk'));
        $dryRun = (bool) $this->option('dry-run');

        $stale = ReportExport::query()
            ->where('status', ExportStatus::Ready->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        if ($stale->isEmpty()) {
            $this->info('No expired report exports.');

            return self::SUCCESS;
        }

        $bytes = 0;

        foreach ($stale as $export) {
            $bytes += (int) $export->file_size;

            if ($dryRun) {
                $this->line("would purge {$export->uuid} ({$export->report->value}, {$export->file_path})");

                continue;
            }

            if ($export->file_path !== null && $disk->exists($export->file_path)) {
                $disk->delete($export->file_path);
            }

            $export->update([
                'status' => ExportStatus::Expired,
                'file_path' => null,
            ]);
        }

        $this->info(sprintf(
            '%s %d expired export(s), %s.',
            $dryRun ? 'Would purge' : 'Purged',
            $stale->count(),
            $this->humanBytes($bytes),
        ));

        return self::SUCCESS;
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }

        return $bytes < 1048576
            ? round($bytes / 1024, 1).' KB'
            : round($bytes / 1048576, 1).' MB';
    }
}
