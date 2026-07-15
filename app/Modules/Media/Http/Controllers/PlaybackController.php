<?php

namespace App\Modules\Media\Http\Controllers;

use App\Modules\Catalog\Models\Lesson;
use App\Modules\Media\Services\PlaybackService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Protected playback (M04/M22). `authorize` issues a short-lived token; the
 * delivery endpoints then serve AES-128-encrypted HLS:
 *
 *   • stream/{token}          → the .m3u8 playlist, with key + segment URIs
 *                               rewritten to carry the token (never the raw MP4)
 *   • segment/{token}/{seg}   → an encrypted .ts segment (useless without the key)
 *   • key/{token}             → the raw 16-byte AES key, released ONLY after the
 *                               enrollment (or teacher-preview) gate is re-checked
 *
 * The source and segments live on a private disk, so nothing is reachable except
 * through these token-gated routes.
 */
class PlaybackController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PlaybackService $playback,
    ) {}

    public function authorize(Request $request, Lesson $lesson): JsonResponse
    {
        $result = $this->playback->issue(
            $this->context->tenantOrFail()->getKey(),
            $request->user(),
            $lesson,
            $request->input('device_fingerprint'),
            $request->ip(),
        );

        return response()->json(['data' => $result]);
    }

    /** The encrypted HLS playlist, with key + segment URIs bound to this token. */
    public function stream(string $token): Response
    {
        $rendition = $this->playback->renditionForToken($token);
        $playlist = Storage::disk($this->disk())->get($rendition->hls_dir.'/index.m3u8');

        $keyUrl = url("/api/v1/media/key/{$token}");
        $segBase = url("/api/v1/media/segment/{$token}");

        // Point the #EXT-X-KEY at our token-gated key endpoint, and each relative
        // segment name at the token-gated segment endpoint.
        $playlist = preg_replace('/URI="[^"]*"/', 'URI="'.$keyUrl.'"', (string) $playlist, 1);
        $playlist = preg_replace_callback('/^(seg_\d+\.ts)$/m', fn ($m) => $segBase.'/'.$m[1], (string) $playlist);

        return response($playlist, 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** A single encrypted segment (range-enabled). Decryptable only with the key. */
    public function segment(string $token, string $segment): BinaryFileResponse
    {
        abort_unless((bool) preg_match('/^seg_\d+\.ts$/', $segment), 404);

        $rendition = $this->playback->renditionForToken($token);
        $disk = Storage::disk($this->disk());
        $path = $rendition->hls_dir.'/'.$segment;

        abort_unless($disk->exists($path), 404);

        return response()->file($disk->path($path), ['Content-Type' => 'video/mp2t']);
    }

    /** Raw 16-byte AES key — released only after access is re-checked. */
    public function key(string $token): Response
    {
        return response($this->playback->resolveKey($token), 200, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function disk(): string
    {
        return (string) config('media.disk', 'local');
    }
}
