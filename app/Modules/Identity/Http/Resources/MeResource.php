<?php

namespace App\Modules\Identity\Http\Resources;

use App\Models\User;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /me payload: the user, all their tenant memberships (identity spans
 * tenants), and their role in the current tenant. Granular permissions are
 * P1.5 — `permissions` is a placeholder until then.
 *
 * @mixin User
 */
class MeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $context = app(TenantContext::class);
        $currentTenant = $context->tenant();
        $currentMembership = $currentTenant !== null ? $this->membershipFor($currentTenant) : null;

        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'locale' => $this->locale,
            'email_verified' => $this->email_verified_at !== null,
            'phone_verified' => $this->phone_verified_at !== null,
            'is_platform_admin' => $this->isPlatformAdmin(),
            'memberships' => $this->memberships->map(fn ($m) => [
                'tenant' => $m->tenant?->slug,
                'tenant_name' => $m->tenant?->name,
                'role' => $m->role->value,
                'status' => $m->status->value,
            ])->all(),
            'current' => [
                'tenant' => $currentTenant?->slug,
                'role' => $currentMembership?->role->value,
                'permissions' => [], // P1.5 (granular permissions)
            ],
        ];
    }
}
