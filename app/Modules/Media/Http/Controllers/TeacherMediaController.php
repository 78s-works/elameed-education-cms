<?php

namespace App\Modules\Media\Http\Controllers;

use App\Modules\Catalog\Models\Lesson;
use App\Modules\Media\Contracts\MediaProvider;
use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Http\Resources\MediaAssetResource;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Services\PlaybackService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Teacher video pipeline (M04). The uploaded source is stored on a PRIVATE disk
 * and is never served directly. Playback (teacher preview or student) goes
 * through the token-gated, AES-128-encrypted HLS flow with a per-student
 * burned-in watermark; the source is transcoded per viewer on first play.
 *
 * Two upload paths, both returning `{ data: { media, upload } }` (so the client
 * always reads the id at `data.media.uuid`):
 *   • Direct (dev/local): send the `file` (multipart) → stored, marked ready.
 *   • Async (production): send no file → returns a signed `upload` target; the
 *     client uploads straight to object storage, then calls `.../complete`.
 */
class TeacherMediaController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly MediaProvider $provider,
        private readonly PlaybackService $playback,
    ) {}

    public function startUpload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lesson_id' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:255'],
            'filename' => ['required_without:file', 'string', 'max:255'],
            'file' => ['sometimes', 'file', 'mimetypes:video/mp4,video/quicktime,video/webm,video/x-matroska', 'max:1048576'],
        ]);

        $tenantId = $this->context->tenantOrFail()->getKey();
        $lesson = $this->resolveLesson($data['lesson_id'] ?? null);

        $asset = new MediaAsset([
            'lesson_id' => $lesson?->getKey(),
            'type' => MediaType::HlsVideo->value,
            'status' => MediaStatus::Uploading->value,
            'title' => $data['title'] ?? ($request->file('file')?->getClientOriginalName() ?? ($data['filename'] ?? 'video')),
        ]);
        $asset->tenant_id = $tenantId;

        if ($request->hasFile('file')) {
            // Direct local upload: store the source privately and mark it ready.
            // The encrypted, watermarked HLS is produced per viewer on first play.
            $asset->source_key = $request->file('file')->store('media/source', $this->disk());
            $asset->status = MediaStatus::Ready->value;
            $asset->save();

            $this->linkLessonVideo($lesson, $asset);

            return response()->json([
                'data' => ['media' => (new MediaAssetResource($asset->fresh()))->resolve($request), 'upload' => null],
            ], 201);
        }

        // Async path — client uploads to object storage then calls complete.
        $asset->save();

        return response()->json([
            'data' => ['media' => (new MediaAssetResource($asset))->resolve($request), 'upload' => $this->provider->createUploadTarget($asset)],
        ], 201);
    }

    /**
     * Async-pipeline receiver for the signed `upload_url` (dev stub for a cloud
     * presigned PUT). No tenant/bearer — the URL signature is the auth. Stores the
     * raw body (or a multipart `file`) privately and marks the asset ready.
     */
    public function receiveUpload(Request $request, string $uuid): JsonResponse
    {
        $asset = MediaAsset::withoutGlobalScopes()->where('uuid', $uuid)->firstOrFail();

        $bytes = $request->hasFile('file')
            ? file_get_contents($request->file('file')->getRealPath())
            : $request->getContent();

        if ($bytes === '' || $bytes === false) {
            throw ValidationException::withMessages(['file' => 'The upload body was empty.']);
        }

        $path = "media/source/{$uuid}.mp4";
        Storage::disk($this->disk())->put($path, $bytes);

        $asset->forceFill(['source_key' => $path, 'status' => MediaStatus::Ready->value])->save();

        if ($asset->lesson_id) {
            Lesson::withoutGlobalScopes()->whereKey($asset->lesson_id)->update(['video_asset_id' => $asset->getKey()]);
        }

        return response()->json(['data' => (new MediaAssetResource($asset->fresh()))->resolve($request)]);
    }

    public function completeUpload(Request $request, MediaAsset $media): JsonResponse
    {
        if ($media->status !== MediaStatus::Ready) {
            $media->update(['status' => MediaStatus::Ready->value]);
            $this->linkLessonVideo($media->lesson_id ? Lesson::query()->find($media->lesson_id) : null, $media);
        }

        return response()->json(['data' => (new MediaAssetResource($media->fresh()))->resolve($request)]);
    }

    public function show(Request $request, MediaAsset $media): MediaAssetResource
    {
        return new MediaAssetResource($media);
    }

    /**
     * Teacher preview → same encrypted-HLS flow as students, watermarked with the
     * teacher's own name. Returns { manifest_url, key_url, token, expires_at }.
     */
    public function preview(Request $request, MediaAsset $media): JsonResponse
    {
        $result = $this->playback->issuePreview(
            $this->context->tenantOrFail()->getKey(),
            $request->user(),
            $media,
            $request->ip(),
        );

        return response()->json(['data' => $result]);
    }

    private function resolveLesson(?int $lessonId): ?Lesson
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

    private function linkLessonVideo(?Lesson $lesson, MediaAsset $asset): void
    {
        if ($lesson !== null) {
            Lesson::query()->whereKey($lesson->getKey())->update(['video_asset_id' => $asset->getKey()]);
        }
    }

    private function disk(): string
    {
        return (string) config('media.disk', 'local');
    }
}
