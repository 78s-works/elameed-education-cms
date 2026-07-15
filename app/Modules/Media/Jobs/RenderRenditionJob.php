<?php

namespace App\Modules\Media\Jobs;

use App\Models\User;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Models\MediaRendition;
use App\Modules\Media\Services\HlsTranscoder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Produces one viewer's watermark-burned, AES-128-encrypted HLS rendition and
 * uploads it to the media store. Runs off the request thread so playback
 * authorization returns immediately (the client polls until the rendition is
 * ready). Retries transient FFmpeg / upload failures; a terminal failure marks
 * the rendition `failed` so the client stops polling instead of hanging.
 */
class RenderRenditionJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;

    /** Drop the uniqueness lock after this long even if the job never completes. */
    public int $uniqueFor = 3600;

    /** Seconds between retries — transcodes are heavy, so back off generously. */
    public array $backoff = [30, 120];

    public function __construct(
        public int $mediaAssetId,
        public int $userId,
        public string $watermark,
    ) {}

    /** Idempotency key so the same rendition isn't transcoded twice concurrently. */
    public function uniqueId(): string
    {
        return "render:{$this->mediaAssetId}:{$this->userId}";
    }

    public function handle(HlsTranscoder $transcoder): void
    {
        $asset = MediaAsset::withoutGlobalScopes()->find($this->mediaAssetId);
        $user = User::find($this->userId);

        // Asset or user gone (deleted mid-flight) — nothing to do.
        if ($asset === null || $user === null) {
            return;
        }

        $transcoder->ensureRendition($asset, $user, $this->watermark);
    }

    public function failed(\Throwable $e): void
    {
        MediaRendition::withoutGlobalScopes()
            ->where('media_asset_id', $this->mediaAssetId)
            ->where('user_id', $this->userId)
            ->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000)]);
    }
}
