<?php

namespace App\Modules\Media\Http\Controllers;

use App\Modules\Catalog\Models\Lesson;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Services\PlaybackService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Protected playback (M04/M22). POST issues a short-lived token + signed manifest
 * for an authorized enrollment; the key endpoint releases the AES key only after
 * re-checking access.
 *
 * The `stream`/`file` endpoints are the dev (LocalMediaProvider) fallback that
 * serves the stored source file directly, range-enabled, so uploaded videos play
 * in a <video> element. Prod serves encrypted HLS from the edge instead.
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

    public function key(string $token): JsonResponse
    {
        return response()->json(['data' => ['key' => $this->playback->resolveKey($token)]]);
    }

    /** Student playback: stream the source for a valid, access-checked token. */
    public function stream(string $token): BinaryFileResponse
    {
        return $this->serve($this->playback->assetForToken($token));
    }

    /** Teacher preview: stream the source via a URL-signed link (no headers needed). */
    public function file(string $uuid): BinaryFileResponse
    {
        return $this->serve(MediaAsset::withoutGlobalScopes()->where('uuid', $uuid)->first());
    }

    private function serve(?MediaAsset $asset): BinaryFileResponse
    {
        abort_if(
            $asset === null || ! $asset->source_key || ! Storage::disk('public')->exists($asset->source_key),
            404,
            'No source file for this media.'
        );

        $ext = strtolower(pathinfo($asset->source_key, PATHINFO_EXTENSION));
        $type = match ($ext) {
            'mp4', 'm4v' => 'video/mp4',
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            'mkv' => 'video/x-matroska',
            default => 'application/octet-stream',
        };

        // response()->file() honours the Range header (206 partial) so the browser
        // can seek; explicit Content-Type avoids finfo mis-detecting the container.
        return response()->file(Storage::disk('public')->path($asset->source_key), ['Content-Type' => $type]);
    }
}
