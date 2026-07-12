<?php

namespace App\Modules\Catalog\Http\Controllers\Teacher;

use App\Modules\Catalog\Http\Requests\AttachmentRequest;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Http\Resources\MediaAssetResource;
use App\Modules\Media\Models\MediaAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * /teacher/lessons/{lesson}/attachments (FR-M04-01) — PDF/file/link materials.
 *
 * P1 stores uploaded files on the default disk (`public`). The self-hosted video
 * pipeline + object storage arrive in the Media step; this endpoint handles the
 * non-video materials.
 */
class LessonAttachmentController
{
    public function index(Lesson $lesson): AnonymousResourceCollection
    {
        return MediaAssetResource::collection(
            $lesson->attachments()->orderBy('sort_order')->get()
        );
    }

    public function store(AttachmentRequest $request, Lesson $lesson): JsonResponse
    {
        $data = $request->validated();

        $attrs = [
            'lesson_id' => $lesson->id,
            'type' => $data['type'],
            'status' => MediaStatus::Ready->value,
            'title' => $data['title'] ?? null,
            'downloadable' => $data['downloadable'] ?? false,
        ];

        if ($data['type'] === 'link') {
            $attrs['url'] = $data['url'];
        } else {
            $path = $request->file('file')->store('attachments', 'public');
            $attrs['source_key'] = $path;
            $attrs['url'] = Storage::disk('public')->url($path);
        }

        $asset = MediaAsset::create($attrs);

        return (new MediaAssetResource($asset))->response()->setStatusCode(201);
    }

    public function destroy(Lesson $lesson, MediaAsset $attachment): Response
    {
        abort_unless($attachment->lesson_id === $lesson->id, 404);
        abort_if($attachment->type === MediaType::HlsVideo, 404); // the video isn't an attachment

        if ($attachment->source_key !== null) {
            Storage::disk('public')->delete($attachment->source_key);
        }

        $attachment->delete();

        return response()->noContent();
    }
}
