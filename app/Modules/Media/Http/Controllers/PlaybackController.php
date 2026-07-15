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

        // 202 while the viewer's encrypted rendition is still transcoding; the
        // client polls until it flips to 200 with a token.
        $status = ($result['status'] ?? 'ready') === 'processing' ? 202 : 200;

        return response()->json(['data' => $result], $status);
    }

    /**
     * The encrypted HLS playlist. The key URI always points at the token-gated
     * app endpoint (tiny, access re-checked). Segment URIs point DIRECTLY at the
     * store via short-lived presigned URLs when the disk supports them
     * (production) — so the app never proxies video bytes and the player can
     * range-request/seek straight from the store. On a local disk (dev/test) the
     * segments fall back to the app-proxied endpoint.
     */
    public function stream(string $token): Response
    {
        $rendition = $this->playback->renditionForToken($token);
        $disk = Storage::disk($this->disk());
        $playlist = (string) $disk->get($rendition->hls_dir.'/index.m3u8');

        $keyUrl = url("/api/v1/media/key/{$token}");
        $playlist = preg_replace('/URI="[^"]*"/', 'URI="'.$keyUrl.'"', $playlist, 1);

        if ($this->directDelivery($disk)) {
            $expiry = now()->addSeconds((int) config('media.stream_ttl', 90));
            $playlist = preg_replace_callback(
                '/^(seg_\d+\.ts)$/m',
                fn ($m) => $disk->temporaryUrl($rendition->hls_dir.'/'.$m[1], $expiry),
                $playlist,
            );
        } else {
            $segBase = url("/api/v1/media/segment/{$token}");
            $playlist = preg_replace_callback('/^(seg_\d+\.ts)$/m', fn ($m) => $segBase.'/'.$m[1], $playlist);
        }

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

    /**
     * Deliver segments straight from the store (presigned) vs. proxy them through
     * the app. Direct delivery is used only with the remote provider on a disk
     * that can actually mint presigned URLs; the local dev disk proxies instead.
     */
    private function directDelivery(\Illuminate\Contracts\Filesystem\Filesystem $disk): bool
    {
        return in_array((string) config('media.provider'), ['remote', 's3'], true)
            && $disk->providesTemporaryUrls();
    }
}
