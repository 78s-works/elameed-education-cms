<?php

namespace App\Modules\Media\Support;

use App\Modules\Media\Models\MediaAsset;

/**
 * Single source of truth for object keys on the media store. Every key is
 * prefixed with the owning tenant, so a store-level misconfiguration cannot let
 * one academy's objects sit under another's prefix — defence-in-depth on top of
 * the DB tenant scope and the per-playback access gate. Keys are never used as a
 * security boundary themselves (delivery is via presigned URLs), only as tidy,
 * collision-free, tenant-partitioned storage.
 */
final class MediaPaths
{
    public static function sourceKey(MediaAsset $asset): string
    {
        return self::tenantPrefix((int) $asset->tenant_id)."/source/{$asset->uuid}.mp4";
    }

    /** Directory holding index.m3u8 + seg_*.ts for one (asset, viewer) rendition. */
    public static function hlsDir(MediaAsset $asset, int|string $userId): string
    {
        return self::hlsRoot($asset)."/{$userId}";
    }

    /** Parent directory of every rendition for an asset (for bulk cleanup). */
    public static function hlsRoot(MediaAsset $asset): string
    {
        return self::tenantPrefix((int) $asset->tenant_id)."/hls/{$asset->uuid}";
    }

    private static function tenantPrefix(int $tenantId): string
    {
        return "media/t{$tenantId}";
    }
}
