<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Http\Requests\UpdateCustomLandingRequest;
use App\Modules\Tenancy\Models\TeacherProfile;
use Illuminate\Http\JsonResponse;

/**
 * GET/PUT /teacher/custom-landing — the academy's landing-mode switch (FR-M02).
 * When ON, the SPA renders its own bundled `custom/<tenant-slug>/` page instead
 * of the CMS landing sections; when OFF (the default), it renders the resolved
 * sections from GET /tenant/landing. The flag is mirrored into GET /tenant/context
 * (`data.landing.custom_enabled`) so the SPA can decide which to load on boot.
 * Operates on the current tenant's single teacher_profiles row (BelongsToTenant
 * scopes + auto-fills tenant_id).
 */
class TeacherCustomLandingController
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->payload($this->profile())]);
    }

    public function update(UpdateCustomLandingRequest $request): JsonResponse
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

    /** @return array{custom_landing_enabled: bool} */
    private function payload(TeacherProfile $profile): array
    {
        return [
            'custom_landing_enabled' => (bool) $profile->custom_landing_enabled,
        ];
    }
}
