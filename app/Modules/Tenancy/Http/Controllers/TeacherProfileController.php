<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Http\Requests\UpdateTeacherProfileRequest;
use App\Modules\Tenancy\Http\Resources\TeacherProfileResource;
use App\Modules\Tenancy\Models\TeacherProfile;
use Illuminate\Http\JsonResponse;

/**
 * GET/PUT /teacher/profile — branding (FR-M02-03). Operates on the current
 * tenant's single profile row; BelongsToTenant scopes + auto-fills tenant_id.
 */
class TeacherProfileController
{
    public function show(): TeacherProfileResource
    {
        return new TeacherProfileResource($this->profile());
    }

    public function update(UpdateTeacherProfileRequest $request): JsonResponse
    {
        $profile = $this->profile();
        $profile->fill($request->validated())->save();

        // PUT is an upsert → always 200 (a resource of a just-created row would
        // otherwise auto-respond 201).
        return (new TeacherProfileResource($profile))->response()->setStatusCode(200);
    }

    /** Current tenant's profile, not persisted until saved (GET must not write). */
    private function profile(): TeacherProfile
    {
        return TeacherProfile::query()->firstOrNew([]);
    }
}
