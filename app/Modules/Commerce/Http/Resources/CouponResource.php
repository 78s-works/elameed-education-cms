<?php

namespace App\Modules\Commerce\Http\Resources;

use App\Modules\Commerce\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Coupon
 */
class CouponResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'code' => $this->code,
            'type' => $this->type->value,
            'value' => $this->value,
            'course' => $this->whenLoaded('course', fn () => $this->course?->uuid),
            'min_subtotal_minor' => $this->min_subtotal_minor,
            'usage_limit' => $this->usage_limit,
            'used_count' => $this->used_count,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'is_active' => $this->is_active,
            'redeemable' => $this->isRedeemable(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
