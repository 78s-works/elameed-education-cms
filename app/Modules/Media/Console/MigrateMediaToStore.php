<?php

namespace App\Modules\Media\Console;

use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Models\MediaRendition;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Safe, resumable migration of locally-stored video (source MP4 + encrypted HLS
 * renditions) onto the external media store, WITHOUT touching the database
 * relationships. Object keys are preserved exactly, so `media_assets.source_key`
 * and `media_renditions.hls_dir` keep resolving after MEDIA_DISK is flipped to
 * the store — no lesson ever breaks.
 *
 * The copy is non-destructive (local files are left in place until you're happy)
 * and idempotent (objects already on the destination are skipped), so it can be
 * run repeatedly and interrupted safely. Use --dry-run to preview.
 *
 *   php artisan media:migrate-to-store --from=media_local --dry-run
 *   php artisan media:migrate-to-store --from=media_local
 *   # then set MEDIA_DISK=media (+ MEDIA_PROVIDER=remote), php artisan config:clear
 */
class MigrateMediaToStore extends Command
{
    protected $signature = 'media:migrate-to-store
        {--from=local : Disk the videos currently live on}
        {--dry-run : Report what would be copied without writing}
        {--chunk=100 : DB rows per batch}';

    protected $description = 'Copy local video sources + encrypted renditions to the external media store, preserving DB relationships.';

    public function handle(): int
    {
        $fromName = (string) $this->option('from');
        $toName = (string) config('media.disk', 'local');

        if ($fromName === $toName) {
            $this->warn("Source and destination disk are both '{$toName}'; nothing to migrate.");

            return self::SUCCESS;
        }

        $from = Storage::disk($fromName);
        $to = Storage::disk($toName);
        $dry = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));

        $this->info(($dry ? '[DRY RUN] ' : '')."Migrating media: '{$fromName}' → '{$toName}'");

        $stats = ['copied' => 0, 'skipped' => 0, 'missing' => 0];

        $this->migrateSources($from, $to, $dry, $chunk, $stats);
        $this->migrateRenditions($from, $to, $dry, $chunk, $stats);

        $this->newLine();
        $this->info(sprintf(
            '%sDone. Copied: %d, already present: %d, missing on source: %d.',
            $dry ? '[DRY RUN] ' : '', $stats['copied'], $stats['skipped'], $stats['missing'],
        ));

        if (! $dry) {
            $this->line('Verify playback, then set MEDIA_DISK to the store (and MEDIA_PROVIDER=remote) and run `php artisan config:clear`.');
        }

        return self::SUCCESS;
    }

    private function migrateSources(Filesystem $from, Filesystem $to, bool $dry, int $chunk, array &$stats): void
    {
        MediaAsset::withoutGlobalScopes()
            ->whereNotNull('source_key')
            ->chunkById($chunk, function ($assets) use ($from, $to, $dry, &$stats): void {
                foreach ($assets as $asset) {
                    $this->copyObject($from, $to, (string) $asset->source_key, $dry, $stats);
                }
            });
    }

    private function migrateRenditions(Filesystem $from, Filesystem $to, bool $dry, int $chunk, array &$stats): void
    {
        MediaRendition::withoutGlobalScopes()
            ->whereNotNull('hls_dir')
            ->chunkById($chunk, function ($renditions) use ($from, $to, $dry, &$stats): void {
                foreach ($renditions as $rendition) {
                    foreach ($from->files($rendition->hls_dir) as $file) {
                        $this->copyObject($from, $to, $file, $dry, $stats);
                    }
                }
            });
    }

    private function copyObject(Filesystem $from, Filesystem $to, string $path, bool $dry, array &$stats): void
    {
        if ($path === '' || ! $from->exists($path)) {
            $stats['missing']++;

            return;
        }

        if ($to->exists($path)) {
            $stats['skipped']++;

            return;
        }

        if (! $dry) {
            $stream = $from->readStream($path);
            $to->writeStream($path, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $stats['copied']++;
        $this->line(($dry ? '  would copy ' : '  copied ').$path);
    }
}
