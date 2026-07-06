<?php

namespace App\Modules\PlatformAdmin\Http\Resources;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Tenant
 */
class AdminTenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'name' => $this->name,
            'status' => $this->status->value,
            'owner_user_id' => $this->owner_user_id,
            'primary_host' => $this->whenLoaded('domains', fn () => $this->domains->firstWhere('is_primary', true)?->host),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
