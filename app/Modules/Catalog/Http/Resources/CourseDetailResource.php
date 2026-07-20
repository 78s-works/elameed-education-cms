<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public course detail (GET /courses/{slug}) — the outline a prospective student
 * sees. Exposes the published units/lessons with preview flags only; actual
 * playback is gated by enrollment + the playback-authz endpoint (Media step).
 *
 * @mixin Course
 */
class CourseDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'slug' => $this->slug,
            'description' => $this->description,
            'learning_outcomes' => $this->learning_outcomes ?? [],
            'requirements' => $this->requirements ?? [],
            'audience' => $this->audience ?? [],
            'parts' => $this->parts ?? [],
            'cover_url' => $this->cover_url,
            'thumbnail_url' => $this->thumbnail_url,
            'promo_video_url' => $this->promo_video_url,
            'price_minor' => $this->price_minor,
            'currency' => $this->currency,
            'is_free' => $this->is_free,
            'access_days' => $this->access_days,
            'category' => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ] : null,
            'units' => $this->units->map(fn ($unit) => [
                'id' => $unit->id,
                'title' => $unit->title,
                'sort_order' => $unit->sort_order,
                'lessons' => $unit->lessons->map(fn ($lesson) => [
                    'id' => $lesson->id,
                    'title' => $lesson->title,
                    'duration_sec' => $lesson->duration_sec,
                    'is_free_preview' => $lesson->is_free_preview,
                    'has_video' => $lesson->video_asset_id !== null,
                ])->values(),
            ])->values(),
        ];
    }
}
