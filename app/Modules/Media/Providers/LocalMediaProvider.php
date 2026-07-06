<?php

namespace App\Modules\Media\Providers;

use App\Modules\Media\Contracts\MediaProvider;
use App\Modules\Media\Models\MediaAsset;
use Illuminate\Support\Facades\URL;

/**
 * Dev/stub provider. The upload target, manifest URL, and AES key are
 * placeholders so the full authorization flow works before the real self-hosted
 * pipeline (chunked upload → FFmpeg → encrypted HLS → nginx edge) exists. The
 * KEY is derived per-asset only for local testing — a real key store replaces it.
 */
class LocalMediaProvider implements MediaProvider
{
    public function name(): string
    {
        return 'local';
    }

    public function createUploadTarget(MediaAsset $asset): array
    {
        // Mirrors a cloud presigned PUT: a time-limited, signed URL the client
        // uploads the raw file to. It lives under /api so the browser CORS policy
        // (paths: api/*) covers it; a real object-storage target replaces this.
        return [
            'upload_url' => URL::temporarySignedRoute('media.upload.receive', now()->addHours(6), ['uuid' => $asset->uuid]),
            'method' => 'PUT',
        ];
    }

    public function manifestUrl(MediaAsset $asset, string $token): string
    {
        // Dev stub: rather than an encrypted-HLS manifest, hand back a direct,
        // range-enabled stream of the stored source, gated by the playback token,
        // so a plain <video> element plays it. Prod returns a real signed .m3u8.
        return url("/api/v1/media/stream/{$token}");
    }

    public function encryptionKey(MediaAsset $asset): string
    {
        // STUB: deterministic per-asset key. Real impl fetches from a key store.
        return base64_encode(hash_hmac('sha256', (string) $asset->uuid, (string) config('app.key'), true));
    }
}
