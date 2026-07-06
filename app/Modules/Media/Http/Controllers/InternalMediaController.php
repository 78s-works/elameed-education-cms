<?php

namespace App\Modules\Media\Http\Controllers;

use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Services\PlaybackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Internal endpoints for the media tier (not client-facing).
 *   - authz: the nginx `auth_request` target — validates a playback token per
 *     manifest/segment request (02_Architecture.md §7, DoD §7.2).
 *   - transcodeCallback: the FFmpeg worker reports ready/failed (signed).
 */
class InternalMediaController
{
    public function __construct(private readonly PlaybackService $playback) {}

    public function authz(Request $request): Response
    {
        $token = (string) $request->query('token', $request->header('X-Playback-Token', ''));

        return $this->playback->authorizeToken($token)
            ? response('', 204)
            : response('', 403);
    }

    public function transcodeCallback(Request $request): JsonResponse
    {
        if (! hash_equals((string) config('media.transcode_secret'), (string) $request->header('X-Transcode-Secret'))) {
            return response()->json(['error' => ['code' => 'forbidden', 'message' => 'Bad secret.']], 403);
        }

        $data = $request->validate([
            'media_uuid' => ['required', 'string'],
            'status' => ['required', 'in:ready,failed'],
            'hls_path' => ['nullable', 'string'],
            'renditions' => ['nullable', 'array'],
        ]);

        $asset = MediaAsset::withoutGlobalScopes()->where('uuid', $data['media_uuid'])->first();
        if ($asset === null) {
            return response()->json(['error' => ['code' => 'not_found', 'message' => 'Unknown asset.']], 404);
        }

        $asset->update([
            'status' => $data['status'] === 'ready' ? MediaStatus::Ready->value : MediaStatus::Failed->value,
            'hls_path' => $data['hls_path'] ?? $asset->hls_path,
            'renditions' => $data['renditions'] ?? $asset->renditions,
        ]);

        return response()->json(['data' => ['status' => $asset->status->value]]);
    }
}
