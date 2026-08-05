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
            // Standalone-lesson fields (VD change set §7). `name` is the public
            // alias of the `title` column; access_mode is the part ceiling.
            'name' => $this->title,
            'access_mode' => $this->access_mode?->value,
            'price_minor' => $this->price_minor,
            'currency' => $this->currency,
            'is_purchasable' => (bool) $this->is_purchasable,
            'academic_year_id' => $this->whenLoaded('academicYear', fn () => $this->academicYear?->uuid),
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
            // Time-boxed access config (null availability_days = unlimited).
            'availability_days' => $this->availability_days,
            'max_extensions' => $this->max_extensions,
            'extension_hours' => $this->extension_hours,
            // Auto self-reopen budget (VD R3/R4) — the instant, no-staff slice of
            // the extension budget; staff approval handles the rest past it.
            'self_reopen_limit' => (int) $this->self_reopen_limit,
            // One (uploaded) video when loaded + the many attachments (pdf/file/link).
            'video' => $this->whenLoaded('videoAsset', fn () => $this->videoAsset ? new MediaAssetResource($this->videoAsset) : null),
            'attachments' => MediaAssetResource::collection($this->whenLoaded('attachments')),
            'sections' => LessonSectionResource::collection($this->whenLoaded('sections')),
        ];
    }
}
