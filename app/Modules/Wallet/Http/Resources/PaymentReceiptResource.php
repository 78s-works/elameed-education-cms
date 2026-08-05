<?php

namespace App\Modules\Wallet\Http\Resources;

use App\Modules\Engagement\Http\Resources\AttachmentResource;
use App\Modules\Wallet\Models\PaymentReceipt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PaymentReceipt
 */
class PaymentReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'method' => $this->method,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'status' => $this->status,
            'reject_reason' => $this->reject_reason,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'student' => $this->whenLoaded('user', fn () => $this->user ? [
                'uuid' => $this->user->uuid,
                'name' => $this->user->name,
            ] : null),
            'reviewer' => $this->whenLoaded('reviewer', fn () => $this->reviewer ? [
                'uuid' => $this->reviewer->uuid,
                'name' => $this->reviewer->name,
            ] : null),
            'attachment' => $this->whenLoaded('attachment', fn () => $this->attachment
                ? (new AttachmentResource($this->attachment))->resolve($request)
                : null),
        ];
    }
}
