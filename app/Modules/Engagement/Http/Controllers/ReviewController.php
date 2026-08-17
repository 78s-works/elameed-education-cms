<?php

namespace App\Modules\Engagement\Http\Controllers;

use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Package;
use App\Modules\Commerce\Services\EnrollmentService;
use App\Modules\Engagement\Http\Requests\StoreReviewRequest;
use App\Modules\Engagement\Http\Resources\ReviewResource;
use App\Modules\Engagement\Models\Review;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Minimal content reviews (VD §7 — `courses` retired). A student with access to a
 * lesson or package may leave one rating+comment (upserted per target). Public
 * listing feeds the content page; the landing `testimonials` section resolves
 * reviews server-side (see LandingResolver).
 */
class ReviewController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EnrollmentService $enrollments,
    ) {}

    /** Public: recent visible reviews for a content target (?target_type=&target_id=). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'target_type' => ['required', Rule::in(Review::targetTypes())],
            'target_id' => ['required', 'integer'],
        ]);

        $reviews = Review::query()
            ->visible()                       // hidden/moderated reviews never show publicly
            ->forTarget($data['target_type'], (int) $data['target_id'])
            ->with('user:id,name')
            ->latest()
            ->paginate(20);

        return ReviewResource::collection($reviews);
    }

    /** Student: create or update their review of content they have access to. */
    public function store(StoreReviewRequest $request): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $user = $request->user();
        $type = (string) $request->string('target_type');
        $id = $request->integer('target_id');

        abort_unless(
            $this->hasAccessTo($tenantId, $user->getKey(), $type, $id),
            403,
            'Get access to this content before reviewing it.'
        );

        $review = Review::updateOrCreate(
            ['target_type' => $type, 'target_id' => $id, 'user_id' => $user->getKey()],
            ['tenant_id' => $tenantId, 'rating' => $request->integer('rating'), 'comment' => $request->input('comment')],
        );

        return (new ReviewResource($review->load('user:id,name')))
            ->response()->setStatusCode(201);
    }

    /** Access gate per target kind: lesson-access for a lesson, package-access for a package. */
    private function hasAccessTo(int $tenantId, int $userId, string $type, int $id): bool
    {
        return match ($type) {
            Review::TARGET_LESSON => ($lesson = Lesson::find($id)) !== null
                && $this->enrollments->hasLessonAccess($tenantId, $userId, $lesson),
            Review::TARGET_PACKAGE => ($package = Package::withoutGlobalScope('academic_year')->find($id)) !== null
                && $this->enrollments->hasPackageAccess($tenantId, $userId, $package),
            default => false,
        };
    }
}
