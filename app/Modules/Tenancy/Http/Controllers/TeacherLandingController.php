<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Http\Requests\UpdateTeacherLandingRequest;
use App\Modules\Tenancy\Http\Resources\TeacherLandingResource;
use App\Modules\Tenancy\Models\TeacherProfile;
use Illuminate\Http\JsonResponse;

/**
 * GET/PUT /teacher/landing — landing section order + visibility (FR-M02-04).
 * Shares the tenant's teacher_profiles row with the branding endpoint.
 */
class TeacherLandingController
{
    public function show(): TeacherLandingResource
    {
        return new TeacherLandingResource($this->profile());
    }

    public function update(UpdateTeacherLandingRequest $request): JsonResponse
    {
        $profile = $this->profile();
        $profile->fill(['landing_sections' => $request->validated('landing_sections')])->save();

        return (new TeacherLandingResource($profile))->response()->setStatusCode(200);
    }

    private function profile(): TeacherProfile
    {
        return TeacherProfile::query()->firstOrNew([]);
    }
}
