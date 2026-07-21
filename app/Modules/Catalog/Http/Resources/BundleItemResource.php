<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\BundleItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BundleItem
 */
class BundleItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->item_type,
            'sort_order' => $this->sort_order,
            'course' => $this->whenLoaded('course', fn () => $this->course ? [
                'uuid' => $this->course->uuid,
                'title' => $this->course->title,
                'slug' => $this->course->slug,
            ] : null),
            'unit' => $this->whenLoaded('unit', fn () => $this->unit ? [
                'id' => $this->unit->id,
                'title' => $this->unit->title,
                'course_id' => $this->unit->course_id,
            ] : null),
            'lesson' => $this->whenLoaded('lesson', fn () => $this->lesson ? [
                'id' => $this->lesson->id,
                'title' => $this->lesson->title,
                'unit_id' => $this->lesson->unit_id,
                'course_id' => $this->lesson->course_id,
            ] : null),
        ];
    }
}
