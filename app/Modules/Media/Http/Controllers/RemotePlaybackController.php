<?php

namespace App\Modules\Media\Http\Controllers;

use App\Modules\Catalog\Models\Lesson;
use App\Modules\Media\Services\RemoteVideoService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Student-facing playback authorization for remote videos. Delegates to
 * RemoteVideoService, which re-checks tenant + enrollment, confirms the current
 * version is `ready`, binds a playback session, and mints the short-lived signed
 * token. The lesson is tenant-scoped by route-model binding (cross-tenant → 404).
 */
class RemotePlaybackController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly RemoteVideoService $service,
    ) {}

    public function authorize(Request $request, Lesson $lesson): JsonResponse
    {
        $result = $this->service->issuePlayback(
            $this->context->tenantOrFail()->getKey(),
            $request->user(),
            $lesson,
            $request->ip(),
            $request->input('device_fingerprint'),
        );

        return response()->json(['data' => $result]);
    }
}
