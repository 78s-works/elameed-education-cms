<?php

namespace App\Modules\Catalog\Http\Controllers\Teacher;

use App\Modules\Catalog\Http\Resources\LessonExtensionRequestResource;
use App\Modules\Catalog\Models\LessonExtensionRequest;
use App\Modules\Catalog\Services\LessonAvailabilityService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * /teacher/extension-requests ("Lesson Availability & Extension Requests") —
 * staff review of student extension requests. Bound requests are tenant-scoped,
 * so cross-tenant ids 404. Granting extends the student's window.
 */
class ExtensionRequestController
{
    public function __construct(
        private readonly LessonAvailabilityService $availability,
        private readonly TenantContext $context,
    ) {}

    /** Pending requests across the academy's lessons. */
    public function index(): AnonymousResourceCollection
    {
        return LessonExtensionRequestResource::collection(
            LessonExtensionRequest::pending()
                ->with('accessWindow')
                ->orderByDesc('requested_at')
                ->get()
        );
    }

    public function grant(Request $request, LessonExtensionRequest $extensionRequest): LessonExtensionRequestResource
    {
        return $this->decide($request, $extensionRequest, true);
    }

    public function deny(Request $request, LessonExtensionRequest $extensionRequest): LessonExtensionRequestResource
    {
        return $this->decide($request, $extensionRequest, false);
    }

    private function decide(Request $request, LessonExtensionRequest $extensionRequest, bool $grant): LessonExtensionRequestResource
    {
        $tenantId = (int) $this->context->tenantOrFail()->getKey();
        $decided = $this->availability->decide($tenantId, $extensionRequest, $grant, (int) $request->user()->getKey());

        return new LessonExtensionRequestResource($decided->load('accessWindow'));
    }
}
