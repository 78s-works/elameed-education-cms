<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Http\Requests\UpdateTenantAccessRequest;
use App\Modules\Tenancy\Models\TeacherProfile;
use Illuminate\Http\JsonResponse;

/**
 * GET/PUT /teacher/access — the teacher's per-academy access switches (FR-M02):
 * whether students can sign in and whether new students can self-register.
 * Operates on the current tenant's single teacher_profiles row (BelongsToTenant
 * scopes + auto-fills tenant_id). Enforced at login/register — see M11.
 */
class TenantAccessController
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->payload($this->profile())]);
    }

    public function update(UpdateTenantAccessRequest $request): JsonResponse
    {
        $profile = $this->profile();
        $profile->fill($request->validated())->save();

        return response()->json(['data' => $this->payload($profile)]);
    }

    /** Current tenant's profile, not persisted until saved (GET must not write). */
    private function profile(): TeacherProfile
    {
        return TeacherProfile::query()->firstOrNew([]);
    }

    /** @return array{login_enabled: bool, registration_enabled: bool} */
    private function payload(TeacherProfile $profile): array
    {
        return [
            'login_enabled' => (bool) $profile->login_enabled,
            'registration_enabled' => (bool) $profile->registration_enabled,
        ];
    }
}
