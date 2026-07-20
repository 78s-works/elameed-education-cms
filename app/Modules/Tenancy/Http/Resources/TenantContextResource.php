<?php

namespace App\Modules\Tenancy\Http\Resources;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\LandingSchema;
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
        $locale = LandingSchema::normalizeLocales($profile?->locales, $profile?->primary_locale);

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
                // The academy's enabled languages (primary first). Arabic/RTL is
                // the platform default (PRD §9 / NFRs) when the teacher sets none.
                'default' => $locale['primary'],
                'supported' => $locale['locales'],
            ],
            'auth' => [
                // Per-academy access switches the teacher controls. The SPA hides
                // the forms when off; the API enforces it regardless (see M11).
                'login_enabled' => (bool) ($profile?->login_enabled ?? true),
                'registration_enabled' => (bool) ($profile?->registration_enabled ?? true),
            ],
            'features' => [],            // TODO: per-tenant enabled feature flags
        ];
    }
}
