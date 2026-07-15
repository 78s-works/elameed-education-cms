<?php

namespace App\Modules\Media\Jobs;

use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Models\MediaAsset;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Transcode worker. STUB for P1: real workers run FFmpeg → multi-bitrate,
 * AES-128-encrypted HLS and then report readiness via /internal/transcode/callback.
 * Here we simply mark the asset ready so the local flow is end-to-end playable.
 */
class TranscodeVideoJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $mediaAssetId) {}

    public function handle(): void
    {
        $asset = MediaAsset::withoutGlobalScopes()->find($this->mediaAssetId);

        if ($asset === null || $asset->status === MediaStatus::Ready) {
            return;
        }

        $asset->update([
            'status' => MediaStatus::Ready->value,
            'hls_path' => "hls/{$asset->uuid}/master.m3u8",
            'renditions' => [['height' => 720, 'bandwidth' => 2500000]],
        ]);
    }
}
