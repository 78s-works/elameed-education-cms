<?php

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Models\TenantSubscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TenantSubscription
 */
class TenantSubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status->value,
            'price_minor' => (int) $this->price_minor,
            'currency' => $this->currency,
            'started_at' => $this->started_at?->toIso8601String(),
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'renews_at' => $this->renews_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'package' => new PackageResource($this->whenLoaded('package')),
        ];
    }
}
