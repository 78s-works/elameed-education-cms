<?php

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Models\SubscriptionPackage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SubscriptionPackage
 */
class PackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'price_minor' => (int) $this->price_minor,
            'currency' => $this->currency,
            'interval' => $this->interval->value,
            'trial_days' => (int) $this->trial_days,
            'limits' => $this->normalizedLimits(),
            'is_active' => (bool) $this->is_active,
            'sort_order' => (int) $this->sort_order,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** Always expose the full canonical key set; a missing key = null (unlimited). */
    private function normalizedLimits(): array
    {
        $out = [];

        foreach (SubscriptionPackage::LIMIT_KEYS as $key) {
            $out[$key] = $this->limit($key);
        }

        return $out;
    }
}
