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
            'has_video' => $this->video_asset_id !== null,
            'visibility' => $this->visibility?->value,
            'publish_at' => $this->publish_at?->toIso8601String(),
            'attachments' => MediaAssetResource::collection($this->whenLoaded('attachments')),
        ];
    }
}
