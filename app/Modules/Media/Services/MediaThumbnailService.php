<?php

namespace App\Modules\Media\Services;

use App\Modules\Media\Models\MediaAsset;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Generates a poster thumbnail for a LOCAL-provider video by extracting one frame
 * from the source with FFmpeg and storing it on the PUBLIC disk (posters are
 * shown in course/lesson listings, so they are not secret). Best-effort: it never
 * throws — if FFmpeg or the source is unavailable it returns null and the upload
 * proceeds without a thumbnail. Remote videos get their thumbnail from the Media
 * Host processing callback instead.
 */
class MediaThumbnailService
{
    public function forLocalAsset(MediaAsset $asset): ?string
    {
        $ffmpeg = (string) config('media.ffmpeg_bin', 'ffmpeg');
        if ($ffmpeg === '' || ! $asset->source_key) {
            return null;
        }

        try {
            $disk = Storage::disk((string) config('media.disk', 'local'));
            $source = $disk->path($asset->source_key); // local disk exposes a real path
            if (! is_file($source)) {
                return null;
            }

            $relative = "media/thumbnails/{$asset->uuid}.jpg";
            $public = Storage::disk('public');
            $output = $public->path($relative);
            @mkdir(dirname($output), 0775, true);

            $norm = static fn (string $p): string => str_replace('\\', '/', $p);
            $result = Process::timeout(60)->run([
                $ffmpeg, '-y', '-ss', '00:00:01', '-i', $norm($source),
                '-vframes', '1', '-vf', 'scale=640:-2', $norm($output),
            ]);

            if (! $result->successful() || ! is_file($output)) {
                return null;
            }

            return $public->url($relative);
        } catch (\Throwable) {
            return null;
        }
    }
}
