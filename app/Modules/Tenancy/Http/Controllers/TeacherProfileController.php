<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Http\Requests\UpdateTeacherProfileRequest;
use App\Modules\Tenancy\Http\Resources\TeacherProfileResource;
use App\Modules\Tenancy\Models\TeacherProfile;
use App\Modules\Tenancy\Support\EntityVersion;
use Illuminate\Http\JsonResponse;

/**
 * GET/PUT /teacher/profile — branding (FR-M02-03). Operates on the current
 * tenant's single profile row; BelongsToTenant scopes + auto-fills tenant_id.
 *
 * GET returns an `ETag`; PUT honours an optional `If-Match` for optimistic
 * concurrency (412 on mismatch) so two editors don't silently overwrite each
 * other — see EntityVersion.
 */
class TeacherProfileController
{
    public function show(): JsonResponse
    {
        $profile = $this->profile();

        return (new TeacherProfileResource($profile))->response()
            ->header('ETag', EntityVersion::etag($profile));
    }

    public function update(UpdateTeacherProfileRequest $request): JsonResponse
    {
        $profile = $this->profile();

        // Reject the write if the client holds a stale version (opt-in If-Match).
        EntityVersion::assertMatches($request, $profile);

        $profile->fill($request->validated())->save();

        // PUT is an upsert → always 200 (a resource of a just-created row would
        // otherwise auto-respond 201).
        return (new TeacherProfileResource($profile))->response()
            ->setStatusCode(200)
            ->header('ETag', EntityVersion::etag($profile));
    }

    /** Current tenant's profile, not persisted until saved (GET must not write). */
    private function profile(): TeacherProfile
    {
        return TeacherProfile::query()->firstOrNew([]);
    }
}
