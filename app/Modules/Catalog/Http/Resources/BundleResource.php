<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Bundle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Bundle
 */
class BundleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'slug' => $this->slug,
            'description' => $this->description,
            'price_minor' => $this->price_minor,
            'currency' => $this->currency,
            'access_days' => $this->access_days,
            'visibility' => $this->visibility?->value,
            'publish_at' => $this->publish_at?->toIso8601String(),
            'is_free' => $this->is_free,
            'purchase_enabled' => $this->purchase_enabled,
            'cover_url' => $this->cover_url,
            'thumbnail_url' => $this->thumbnail_url,
            'items_count' => $this->whenCounted('items'),
            'items' => BundleItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
