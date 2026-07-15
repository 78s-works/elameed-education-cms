<?php

namespace App\Modules\Media\Services;

use App\Modules\Catalog\Models\Lesson;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Models\PlaybackSession;
use App\Modules\Media\Support\MediaPaths;
use Illuminate\Support\Facades\Storage;

/**
 * Lifecycle/cleanup for media assets on the external store. Deleting or replacing
 * a video must remove BOTH the DB rows and the store objects (source + every
 * encrypted rendition) so nothing is orphaned and no stale bytes keep costing
 * storage or remain retrievable. Rendition rows cascade on the asset FK; playback
 * sessions and store objects do not, so they are removed explicitly.
 */
class MediaLibrary
{
    /** Remove an asset's objects from the media store (source + all renditions). */
    public function purgeStorage(MediaAsset $asset): void
    {
        $disk = Storage::disk((string) config('media.disk', 'local'));

        if ($asset->source_key) {
            $disk->delete($asset->source_key);
        }

        // All renditions for this asset live under one prefix.
        $disk->deleteDirectory(MediaPaths::hlsRoot($asset));

        // Legacy (pre-migration) renditions used an un-prefixed path — clear those too.
        $disk->deleteDirectory("media/hls/{$asset->uuid}");
    }

    /**
     * Fully delete an asset: store objects, playback sessions, rendition rows
     * (via FK cascade), any lesson link, then the asset row itself.
     */
    public function delete(MediaAsset $asset): void
    {
        $this->purgeStorage($asset);

        PlaybackSession::withoutGlobalScopes()
            ->where('media_asset_id', $asset->getKey())
            ->delete();

        Lesson::withoutGlobalScopes()
            ->where('video_asset_id', $asset->getKey())
            ->update(['video_asset_id' => null]);

        $asset->delete(); // media_renditions cascade on the asset FK
    }
}
