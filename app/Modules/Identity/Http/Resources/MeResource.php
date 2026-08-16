<?php

namespace App\Modules\Identity\Http\Resources;

use App\Models\User;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /me payload: the user, all their tenant memberships (identity spans
 * tenants), and their role + granular permissions in the current tenant (M18).
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
            // VD R5 — the student's study channel + physical center (null for
            // non-students / online-only). Lets the student's own profile screen
            // show study_mode without a separate lookup.
            'study_mode' => $this->studentProfile?->study_mode,
            'center' => $this->studentProfile?->center?->uuid,
            // The student's academic year (grade) — the container their content is
            // scoped to (server-side). Null for non-students / unpinned students.
            'academic_year' => $this->studentProfile?->academicYear
                ? [
                    'uuid' => $this->studentProfile->academicYear->uuid,
                    'name' => $this->studentProfile->academicYear->name,
                ]
                : null,
            'memberships' => $this->memberships->map(fn ($m) => [
                'tenant' => $m->tenant?->slug,
                'tenant_name' => $m->tenant?->name,
                'role' => $m->role->value,
                'status' => $m->status->value,
            ])->all(),
            'current' => [
                'tenant' => $currentTenant?->slug,
                'role' => $currentMembership?->role->value,
                // Granular permissions (M18): teachers get the full catalog,
                // assistants their granted subset, everyone else none.
                'permissions' => $currentMembership?->effectivePermissions() ?? [],
            ],
        ];
    }
}
