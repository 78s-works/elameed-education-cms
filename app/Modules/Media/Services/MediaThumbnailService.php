<?php

namespace App\Modules\Media\Services;

use App\Modules\Media\Models\MediaAsset;
use App\Support\Files\DocumentService;
use App\Support\Files\Enums\DocumentPurpose;
use App\Support\Files\Models\Document;
use App\Support\Files\StoreOptions;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Generates a poster thumbnail for a LOCAL-provider video by extracting one frame
 * from the source with FFmpeg. The poster is public — it shows in lesson listings
 * to visitors who are not logged in — but it is still a tracked document, so it
 * appears in the academy's library and is removed with the video.
 *
 * Best-effort: it never throws. If FFmpeg or the source is unavailable it returns
 * null and the upload proceeds without a poster. Remote videos get theirs from the
 * Media Host processing callback instead.
 */
class MediaThumbnailService
{
    public function __construct(private readonly DocumentService $documents) {}

    public function forLocalAsset(MediaAsset $asset): ?Document
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

            // FFmpeg needs a real path to write to, so the frame is extracted to a
            // temp file and handed to the service, which decides where it lives.
            $temp = tempnam(sys_get_temp_dir(), 'poster').'.jpg';

            $norm = static fn (string $p): string => str_replace('\\', '/', $p);
            $result = Process::timeout(60)->run([
                $ffmpeg, '-y', '-ss', '00:00:01', '-i', $norm($source),
                '-vframes', '1', '-vf', 'scale=640:-2', $norm($temp),
            ]);

            if (! $result->successful() || ! is_file($temp)) {
                return null;
            }

            $document = $this->documents->storeContents(
                (string) file_get_contents($temp),
                "{$asset->uuid}.jpg",
                DocumentPurpose::VideoThumbnail,
                new StoreOptions(ownerId: (int) $asset->sourceDocument?->owner_id),
            );

            @unlink($temp);

            return $document;
        } catch (\Throwable) {
            return null;
        }
    }
}
