<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public course detail (GET /courses/{slug}) — the outline a prospective student
 * sees. Exposes the published lessons with preview flags only; actual playback is
 * gated by enrollment + the playback-authz endpoint (Media step). (Units retired,
 * VD §7 — lessons are a flat published list now.)
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
            'lessons' => $this->lessons->map(fn ($lesson) => [
                'id' => $lesson->id,
                'title' => $lesson->title,
                'sort_order' => $lesson->sort_order,
                'duration_sec' => $lesson->duration_sec,
                'is_free_preview' => $lesson->is_free_preview,
                // Source-aware: true when the ACTIVE source (upload or YouTube) is set.
                // The name of the source is exposed, but never the YouTube URL here —
                // that is released only through the enrollment-gated playback endpoint.
                'has_video' => $lesson->hasActiveVideo(),
                'active_video_source' => $lesson->active_video_source?->value,
            ])->values(),
        ];
    }
}
