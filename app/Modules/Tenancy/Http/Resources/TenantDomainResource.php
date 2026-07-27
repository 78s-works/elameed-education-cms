<?php

namespace App\Modules\Tenancy\Http\Resources;

use App\Modules\Tenancy\Models\TenantDomain;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TenantDomain
 */
class TenantDomainResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'host' => $this->host,
            'type' => $this->type->value,
            'is_primary' => (bool) $this->is_primary,
            'ssl_status' => $this->ssl_status,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
