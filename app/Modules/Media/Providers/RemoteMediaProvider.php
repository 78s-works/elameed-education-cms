<?php

namespace App\Modules\Media\Providers;

use App\Modules\Media\Contracts\MediaProvider;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Support\MediaPaths;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Production provider for an external, S3-compatible media store. The source
 * video is uploaded DIRECTLY from the browser to the store via a short-lived
 * presigned PUT (never through the app), and the encrypted HLS is delivered
 * DIRECTLY from the store via presigned segment URLs minted per playback. The
 * app only ever handles small metadata: the playlist text and the AES key.
 *
 * "Provider" here is the S3 protocol, not a vendor — the same code runs against
 * self-hosted MinIO, Bunny, Backblaze B2, Wasabi, R2, or AWS S3.
 */
class RemoteMediaProvider implements MediaProvider
{
    public function name(): string
    {
        return 'remote';
    }

    /**
     * Presigned PUT straight to the store. The client uploads the raw file to
     * this URL; the app never receives the bytes. The object lands at the asset's
     * deterministic source key so the transcode worker can find it.
     *
     * @return array{upload_url: string, method: string, headers: array<string,string>, key: string}
     */
    public function createUploadTarget(MediaAsset $asset): array
    {
        $disk = Storage::disk((string) config('media.disk'));

        if (! method_exists($disk, 'temporaryUploadUrl')) {
            throw new RuntimeException('The media disk does not support presigned uploads; set MEDIA_DISK to an S3-compatible disk.');
        }

        $key = MediaPaths::sourceKey($asset);
        $target = $disk->temporaryUploadUrl($key, now()->addSeconds((int) config('media.upload_ttl', 3600)));

        return [
            'upload_url' => $target['url'],
            'method' => 'PUT',
            'headers' => $target['headers'] ?? [],
            'key' => $key,
        ];
    }

    /**
     * The app-served playlist endpoint. The playlist TEXT is tiny and gated by
     * the playback token; the segment/key URIs inside it point directly at the
     * store (presigned) so the app never proxies video bytes.
     */
    public function manifestUrl(MediaAsset $asset, string $token): string
    {
        return url("/api/v1/media/stream/{$token}");
    }

    /**
     * The AES-128 key is held per rendition (encrypted at rest) and released only
     * through the token-gated key endpoint after access is re-checked, so this
     * interface method is unused in the wired flow. Kept for contract parity.
     */
    public function encryptionKey(MediaAsset $asset): string
    {
        return (string) $asset->encryption_key_ref;
    }
}
