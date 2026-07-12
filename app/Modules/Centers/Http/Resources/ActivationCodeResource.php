<?php

namespace App\Modules\Centers\Http\Resources;

use App\Modules\Centers\Models\ActivationCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ActivationCode */
class ActivationCodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'code' => $this->code,
            'type' => $this->type->value,
            'amount_minor' => $this->amount_minor,
            'course_id' => $this->course_id,
            'batch' => $this->batch,
            'status' => $this->status->value,
            'redeemed_by' => $this->redeemed_by,
            'redeemed_at' => $this->redeemed_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
