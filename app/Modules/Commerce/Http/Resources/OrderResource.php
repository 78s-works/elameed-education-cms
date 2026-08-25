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
            'subtotal_minor' => $this->subtotal_minor ?? $this->total_minor,
            'discount_minor' => (int) $this->discount_minor,
            'total_minor' => $this->total_minor,
            'currency' => $this->currency,
            'coupon' => $this->whenLoaded('coupon', fn () => $this->coupon?->code),
            'created_at' => $this->created_at?->toIso8601String(),
            // How it was paid, when the caller asked for payments. `gateway` is the
            // channel (paymob|fawry|wallet…); the newest attempt wins.
            'payment_method' => $this->whenLoaded(
                'payments',
                fn () => $this->payments->sortByDesc('id')->first()?->gateway,
            ),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'type' => $i->item_type,
                'title' => $i->title,
                'price_minor' => $i->price_minor,
            ])->values()),
        ];
    }
}
