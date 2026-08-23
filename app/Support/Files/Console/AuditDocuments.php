<?php

namespace App\Support\Files\Console;

use App\Support\Files\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Reconciles the ledger against the disks, in both directions:
 *
 *   • rows whose blob is gone   — a manual disk edit, or a write that half-failed;
 *   • blobs with no row         — files written before this table existed, or by
 *                                 code that bypassed DocumentService.
 *
 * The second direction is the one that matters long term: it is how an untracked
 * upload path gets caught in an environment where the architecture test cannot
 * run. A healthy install reports zero of both.
 */
class AuditDocuments extends Command
{
    protected $signature = 'documents:audit {--delete-orphans : Remove blobs that no document row references}';

    protected $description = 'Report documents without blobs, and blobs without documents';

    public function handle(): int
    {
        $missing = $this->missingBlobs();
        $orphans = $this->orphanBlobs();

        $this->line('Documents with no blob: '.count($missing));
        foreach ($missing as $row) {
            $this->warn("  {$row}");
        }

        $this->line('Blobs with no document: '.count($orphans));
        foreach ($orphans as $row) {
            $this->warn("  {$row}");
        }

        if ($orphans !== [] && $this->option('delete-orphans')) {
            foreach ($orphans as $row) {
                [$disk, $key] = explode(':', $row, 2);
                Storage::disk($disk)->delete($key);
            }
            $this->info('Deleted '.count($orphans).' orphan blob(s).');
        }

        // Non-zero so CI and a cron can both treat a drift as a failure.
        return ($missing === [] && $orphans === []) ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<int, string> */
    private function missingBlobs(): array
    {
        $missing = [];

        Document::query()
            ->withoutGlobalScope('tenant')
            ->chunkById(500, function ($documents) use (&$missing): void {
                foreach ($documents as $document) {
                    if (! Storage::disk($document->disk)->exists($document->storage_key)) {
                        $missing[] = "{$document->uuid} ({$document->purpose->value}) {$document->disk}:{$document->storage_key}";
                    }
                }
            });

        return $missing;
    }

    /**
     * Everything under the managed `tenants/` prefix that no row claims. Files
     * outside that prefix are left alone — the HLS pipeline owns its own tree and
     * is not part of this ledger.
     *
     * @return array<int, string>
     */
    private function orphanBlobs(): array
    {
        $known = Document::query()
            ->withoutGlobalScope('tenant')
            ->pluck('storage_key', 'storage_key');

        $orphans = [];

        foreach ([config('documents.private_disk'), config('documents.public_disk')] as $disk) {
            foreach (Storage::disk($disk)->allFiles('tenants') as $key) {
                if (! isset($known[$key])) {
                    $orphans[] = "{$disk}:{$key}";
                }
            }
        }

        return $orphans;
    }
}
