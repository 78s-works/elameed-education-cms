<?php

namespace App\Modules\Commerce\Http\Resources;

use App\Modules\Commerce\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'number' => $this->number,
            'issued_at' => optional($this->issued_at)->toIso8601String(),
            'pdf_available' => $this->hasPdf(),
            // Access-controlled endpoint — never the raw private storage path.
            'download_url' => url("/api/v1/invoices/{$this->uuid}/download"),
            'order' => $this->whenLoaded('order', fn () => [
                'uuid' => $this->order->uuid,
                'total_minor' => $this->order->total_minor,
                'currency' => $this->order->currency,
                'items' => $this->order->relationLoaded('items')
                    ? $this->order->items->map(fn ($i) => [
                        'type' => $i->item_type,
                        'title' => $i->title,
                        'price_minor' => $i->price_minor,
                    ])->values()
                    : null,
            ]),
        ];
    }
}
