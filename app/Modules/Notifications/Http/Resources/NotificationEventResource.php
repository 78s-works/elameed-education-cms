<?php

namespace App\Modules\Notifications\Http\Resources;

use App\Modules\Notifications\Models\NotificationEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NotificationEvent
 *
 * Read-only audit view (doc 10 §9.1 Events). Surfaces the curated payload and,
 * when loaded, per-recipient delivery/failure counts.
 */
class NotificationEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'type_key' => $this->whenLoaded('type', fn () => $this->type->key),
            'tenant_id' => $this->tenant_id,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'triggered_by' => $this->triggered_by,
            'payload' => $this->payload,
            'delivered_count' => $this->whenCounted('notifications'),
            'failed_count' => $this->whenCounted('failures'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
