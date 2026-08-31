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
        $delivered = $this->whenCounted('delivered');
        $pending = $this->whenCounted('pending');
        $failed = $this->whenCounted('failures');

        return [
            'id' => $this->getKey(),
            'type_key' => $this->whenLoaded('type', fn () => $this->type->key),
            'tenant_id' => $this->tenant_id,
            // The console identifies an academy by name, never by its row id.
            'tenant' => $this->whenLoaded('tenant', fn () => $this->tenant === null ? null : [
                'uuid' => $this->tenant->uuid,
                'name' => $this->tenant->name,
            ]),
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'triggered_by' => $this->triggered_by,
            // A bare user id in the actor column is the same defect as a bare
            // tenant id in the academy one — name whoever set this off.
            'triggered_by_name' => $this->whenLoaded('trigger', fn () => $this->trigger?->name),
            'payload' => $this->payload,
            // The counters are defined so they always reconcile:
            // dispatched = delivered + pending + failed. `delivered` is a
            // recipient x channel message carrying a `sent` log, `pending` is
            // one still queued, `failed` is a recorded delivery failure.
            'delivered_count' => $delivered,
            'pending_count' => $pending,
            'failed_count' => $failed,
            'dispatched_count' => $this->when(
                is_numeric($delivered) && is_numeric($pending) && is_numeric($failed),
                fn () => (int) $delivered + (int) $pending + (int) $failed,
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
