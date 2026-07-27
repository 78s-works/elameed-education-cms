<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\TenantUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An assistant, as the teacher sees them (M18): the shared global identity keyed
 * by uuid + their per-academy membership status and delegated permissions.
 *
 * @mixin TenantUser
 */
class AssistantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->user?->uuid,
            'name' => $this->user?->name,
            'phone' => $this->user?->phone,
            'email' => $this->user?->email,
            'status' => $this->status->value,
            'permissions' => $this->effectivePermissions(),
            'joined_at' => $this->joined_at?->toIso8601String(),
        ];
    }
}
