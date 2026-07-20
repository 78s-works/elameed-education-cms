<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Lesson;
use App\Modules\Media\Http\Resources\MediaAssetResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Lesson
 */
class LessonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'unit_id' => $this->unit_id,
            'course_id' => $this->course_id,
            'title' => $this->title,
            'description' => $this->description,
            'sort_order' => $this->sort_order,
            'duration_sec' => $this->duration_sec,
            'max_views' => $this->max_views,
            'is_free_preview' => $this->is_free_preview,
            'has_video' => $this->hasActiveVideo(),
            // Both video sources + the toggle (teacher-facing — the teacher sees both
            // slots; students only ever receive the ACTIVE one, via the playback endpoint).
            'active_video_source' => $this->active_video_source?->value,
            'youtube_url' => $this->youtube_url,
            'visibility' => $this->visibility?->value,
            'publish_at' => $this->publish_at?->toIso8601String(),
            // One (uploaded) video when loaded + the many attachments (pdf/file/link).
            'video' => $this->whenLoaded('videoAsset', fn () => $this->videoAsset ? new MediaAssetResource($this->videoAsset) : null),
            'attachments' => MediaAssetResource::collection($this->whenLoaded('attachments')),
        ];
    }
}
