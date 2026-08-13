<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Package;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A recursive content package (VD change set §7/§8). `id` is the internal id used
 * for binding and as `item_id` when nesting (lessons/packages are referenced by
 * id, not uuid — lessons carry no uuid); `uuid` is exposed for a future public
 * surface. `items` (when loaded) are ordered and each carries a resolved summary.
 *
 * @mixin Package
 */
class PackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'description' => $this->description,
            'cover_url' => $this->cover_url,
            'promo_video_url' => $this->promo_video_url,
            'access_mode' => $this->access_mode?->value,
            'price_minor' => $this->price_minor,
            'currency' => $this->currency,
            'is_purchasable' => (bool) $this->is_purchasable,
            'type' => $this->when($this->package_type_id !== null, fn () => [
                'id' => $this->packageType?->id,
                'uuid' => $this->packageType?->uuid,
                'name' => $this->packageType?->name,
                // Channel + buy_alone drive the student explore UX (F4): a buy_alone
                // type sells its lessons individually, not the package as a whole.
                'channel' => $this->packageType?->channel,
                'buy_alone' => (bool) $this->packageType?->buy_alone,
            ]),
            'academic_year_id' => $this->whenLoaded('academicYear', fn () => $this->academicYear?->uuid),
            'items_count' => $this->whenCounted('items'),
            'items' => PackageItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
