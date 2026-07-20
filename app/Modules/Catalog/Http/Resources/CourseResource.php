<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Course
 */
class CourseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'slug' => $this->slug,
            'description' => $this->description,
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ] : null),
            'price_minor' => $this->price_minor,
            'currency' => $this->currency,
            'access_days' => $this->access_days,
            'visibility' => $this->visibility?->value,
            'publish_at' => $this->publish_at?->toIso8601String(),
            'is_free' => $this->is_free,
            'purchase_enabled' => $this->purchase_enabled,
            'is_center' => $this->is_center,
            'cover_url' => $this->cover_url,
            'thumbnail_url' => $this->thumbnail_url,
            'promo_video_url' => $this->promo_video_url,
            'points' => $this->points,
        ];
    }
}
