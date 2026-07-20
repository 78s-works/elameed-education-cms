<?php

namespace App\Modules\Media\Http\Controllers;

use App\Modules\Catalog\Enums\VideoSource;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Media\Services\PlaybackService;
use App\Modules\Media\Services\RemoteVideoService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Student-facing playback authorization for remote videos. Delegates to
 * RemoteVideoService, which re-checks tenant + enrollment, confirms the current
 * version is `ready`, binds a playback session, and mints the short-lived signed
 * token. The lesson is tenant-scoped by route-model binding (cross-tenant → 404).
 *
 * YouTube-sourced lessons are provider-independent: whichever playback endpoint
 * the SPA calls for the active MEDIA_PROVIDER, a YouTube lesson returns the same
 * gated embed payload (docs/design/lesson-video-sources.md).
 */
class RemotePlaybackController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly RemoteVideoService $service,
        private readonly PlaybackService $playback,
    ) {}

    public function authorize(Request $request, Lesson $lesson): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();

        if ($lesson->active_video_source === VideoSource::Youtube) {
            return response()->json([
                'data' => $this->playback->issueYoutube($tenantId, $request->user(), $lesson),
            ]);
        }

        $result = $this->service->issuePlayback(
            $tenantId,
            $request->user(),
            $lesson,
            $request->ip(),
            $request->input('device_fingerprint'),
        );

        return response()->json(['data' => $result]);
    }
}
