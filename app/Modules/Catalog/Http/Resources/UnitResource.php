<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Unit
 */
class UnitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'course_id' => $this->course_id,
            'title' => $this->title,
            'sort_order' => $this->sort_order,
            'visibility' => $this->visibility?->value,
            'publish_at' => $this->publish_at?->toIso8601String(),
            'lessons' => LessonResource::collection($this->whenLoaded('lessons')),
        ];
    }
}
