<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Http\Requests\UpdateTeacherLandingRequest;
use App\Modules\Tenancy\Http\Resources\TeacherLandingResource;
use App\Modules\Tenancy\Models\TeacherProfile;
use App\Modules\Tenancy\Services\TenantContext;
use App\Modules\Tenancy\Support\EntityVersion;
use App\Modules\Tenancy\Support\LandingSchema;
use App\Support\Files\DocumentService;
use App\Support\Files\Enums\DocumentPurpose;
use App\Support\Files\StoreOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET/PUT /teacher/landing — landing authoring (FR-M02-04 + EDU enhancement):
 * layout choice + ordered, typed sections with content. Shares the tenant's
 * teacher_profiles row with the branding endpoint. Section images/logo are
 * uploaded via `POST /teacher/landing/media` (public disk — they render on the
 * public landing).
 */
class TeacherLandingController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly DocumentService $documents,
    ) {}

    public function show(): JsonResponse
    {
        $profile = $this->profile();

        return (new TeacherLandingResource($profile))->response()
            ->header('ETag', EntityVersion::etag($profile));
    }

    public function update(UpdateTeacherLandingRequest $request): JsonResponse
    {
        $profile = $this->profile();

        // Reject the write if the client holds a stale version (opt-in If-Match).
        // Both landing + branding save this same row, so either edit bumps it.
        EntityVersion::assertMatches($request, $profile);

        $data = $request->validated();

        // Effective language set: this payload's choice, else keep the academy's.
        $meta = LandingSchema::normalizeLocales(
            $data['locales'] ?? $profile->locales,
            $data['primary_locale'] ?? $profile->primary_locale,
        );

        $fill = [
            'locales' => $meta['locales'],
            'primary_locale' => $meta['primary'],
            'landing_sections' => LandingSchema::sanitize(
                $data['sections'],
                $profile->landing_sections ?? [],
                $meta['locales'],
                $meta['primary'],
            ),
        ];
        if (array_key_exists('layout', $data)) {
            $fill['layout'] = $data['layout'];
        }

        $profile->fill($fill)->save();

        $fresh = $profile->fresh();

        return (new TeacherLandingResource($fresh))->response()
            ->setStatusCode(200)
            ->header('ETag', EntityVersion::etag($fresh));
    }

    /** Upload a landing/branding image (logo, hero bg, avatars) → returns a public URL. */
    public function media(Request $request): JsonResponse
    {
        // Raster formats only. SVG is deliberately excluded: it can embed
        // <script>, and these files are served from the public disk on the same
        // origin as the academy — an uploaded SVG would be a stored-XSS vector.
        $request->validate([
            'file' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,image/gif', 'max:5120'],
        ]);

        $document = $this->documents->store(
            $request->file('file'),
            DocumentPurpose::LandingImage,
            new StoreOptions(owner: $this->context->tenantOrFail()),
        );

        // The URL is what goes into the landing JSON, exactly as before — the
        // page builder stores plain public URLs and is not touched by this work.
        // The uuid rides along so the client can reference the document itself
        // (branding fields, the files tab) without a second lookup.
        return response()->json(['data' => [
            'uuid' => $document->uuid,
            'url' => $document->publicUrl(),
        ]]);
    }

    private function profile(): TeacherProfile
    {
        return TeacherProfile::query()->firstOrNew([]);
    }
}
