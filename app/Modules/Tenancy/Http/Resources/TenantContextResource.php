<?php

namespace App\Modules\Tenancy\Http\Resources;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public tenant context the SPA loads on boot (GET /api/v1/tenant/context):
 * identity + status + branding/theme + enabled features. Branding comes from
 * the tenant's teacher_profile (null fields until the teacher sets them).
 *
 * @mixin Tenant
 */
class TenantContextResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $profile = $this->teacherProfile;

        return [
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'name' => $this->name,
            'status' => $this->status->value,
            'branding' => [
                'logo_url' => $profile?->logo_url,
                'cover_url' => $profile?->cover_url,
                'primary_color' => $profile?->primary_color,
                'secondary_color' => $profile?->secondary_color,
                'bio' => $profile?->bio,
                'socials' => $profile?->socials ?? (object) [],
                // Landing content moved to GET /tenant/landing (LANDING_CONTRACT_V2.md).
                // `layout`/`landing_sections` are no longer served here.
            ],
            'locale' => [
                'default' => 'ar',       // Arabic default, RTL (PRD §9 / NFRs)
                'supported' => ['ar', 'en'],
            ],
            'features' => [],            // TODO: per-tenant enabled feature flags
        ];
    }
}
