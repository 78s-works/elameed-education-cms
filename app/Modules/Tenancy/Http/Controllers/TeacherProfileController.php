<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Http\Requests\UpdateTeacherProfileRequest;
use App\Modules\Tenancy\Http\Resources\TeacherProfileResource;
use App\Modules\Tenancy\Models\TeacherProfile;
use App\Modules\Tenancy\Support\EntityVersion;
use App\Support\Files\Models\Document;
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

        $profile->fill($this->resolveBranding($request->validated()))->save();

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

    /**
     * Turn the branding uuids the client sends into the FK columns the profile
     * stores. Kept here rather than in the model so `fill()` never has to accept
     * a uuid for a column that holds an id.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveBranding(array $data): array
    {
        foreach (['logo', 'favicon', 'cover'] as $slot) {
            $key = $slot.'_document_uuid';

            if (! array_key_exists($key, $data)) {
                continue;
            }

            $uuid = $data[$key];
            unset($data[$key]);

            $data[$slot.'_document_id'] = $uuid === null
                ? null
                : Document::where('uuid', $uuid)->value('id');
        }

        return $data;
    }
}
