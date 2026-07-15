<?php

namespace App\Modules\Media\Http\Controllers\Teacher;

use App\Modules\Catalog\Models\Lesson;
use App\Modules\Media\Http\Requests\StartRemoteUploadRequest;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Models\MediaUploadSession;
use App\Modules\Media\Models\MediaVersion;
use App\Modules\Media\Services\RemoteVideoService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Teacher-facing control of remote (Media Host) videos. Every bound model
 * ({media:uuid}, {session}, {version}) is tenant-scoped by the BelongsToTenant
 * global scope, so a cross-tenant id resolves to 404 — no manual tenant checks
 * needed here.
 */
class RemoteVideoController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly RemoteVideoService $service,
    ) {}

    /** POST /teacher/remote-videos/uploads — create an authorized upload intent. */
    public function startUpload(StartRemoteUploadRequest $request): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $lesson = $this->resolveLesson($request->input('lesson_id'));
        $key = (string) ($request->input('idempotency_key') ?: Str::uuid());

        $result = $this->service->createUploadIntent($tenantId, $request->user(), $lesson, $request->validated(), $key);

        return response()->json(['data' => $result], 201);
    }

    /** POST /teacher/remote-videos/uploads/{session}/complete */
    public function complete(Request $request, MediaUploadSession $session): JsonResponse
    {
        return response()->json(['data' => $this->service->completeUpload($session)]);
    }

    /** POST /teacher/remote-videos/{media:uuid}/replace — atomic replacement. */
    public function replace(StartRemoteUploadRequest $request, MediaAsset $media): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $key = (string) ($request->input('idempotency_key') ?: Str::uuid());

        $result = $this->service->replaceUpload($tenantId, $request->user(), $media, $request->validated(), $key);

        return response()->json(['data' => $result], 201);
    }

    public function retry(Request $request, MediaVersion $version): JsonResponse
    {
        $this->service->retryProcessing($version);

        return response()->json(['data' => ['version' => $version->version, 'state' => $version->fresh()->state->value]]);
    }

    public function quarantine(Request $request, MediaVersion $version): JsonResponse
    {
        $this->service->quarantine($version);

        return response()->json(['data' => ['version' => $version->version, 'state' => $version->fresh()->state->value]]);
    }

    public function restore(Request $request, MediaVersion $version): JsonResponse
    {
        $this->service->restore($version);

        return response()->json(['data' => ['version' => $version->version, 'state' => $version->fresh()->state->value]]);
    }

    public function purge(Request $request, MediaVersion $version): JsonResponse
    {
        $this->service->purge($version);

        return response()->json(['data' => ['version' => $version->version, 'state' => $version->fresh()->state->value]]);
    }

    /** GET /teacher/remote-videos/{media:uuid} — status snapshot. */
    public function show(Request $request, MediaAsset $media): JsonResponse
    {
        $media->load('versions');

        return response()->json(['data' => [
            'video' => $media->uuid,
            'provider' => $media->provider,
            'current_version_id' => $media->current_version_id,
            'thumbnail_url' => $media->thumbnail_url,
            'versions' => $media->versions->map(fn (MediaVersion $v) => [
                'version' => $v->version,
                'state' => $v->state->value,
                'host_video_id' => $v->host_video_id,
                'playback_id' => $v->playback_id,
                'thumbnail_url' => $v->thumbnail_url,
                'duration_sec' => $v->duration_sec,
                'ready_at' => $v->ready_at?->toIso8601String(),
            ])->all(),
        ]]);
    }

    private function resolveLesson(mixed $lessonId): ?Lesson
    {
        if (empty($lessonId)) {
            return null;
        }
        $lesson = Lesson::query()->find($lessonId); // tenant-scoped
        if ($lesson === null) {
            throw ValidationException::withMessages(['lesson_id' => 'Lesson not found in this academy.']);
        }

        return $lesson;
    }
}
