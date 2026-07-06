<?php

namespace App\Modules\Commerce\Http\Resources;

use App\Modules\Commerce\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status->value,
            'total_minor' => $this->total_minor,
            'currency' => $this->currency,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'type' => $i->item_type,
                'title' => $i->title,
                'price_minor' => $i->price_minor,
            ])->values()),
        ];
    }
}
