<?php

namespace App\Modules\Engagement\Http\Controllers;

use App\Modules\Catalog\Models\Course;
use App\Modules\Commerce\Services\EnrollmentService;
use App\Modules\Engagement\Http\Requests\StoreReviewRequest;
use App\Modules\Engagement\Http\Resources\ReviewResource;
use App\Modules\Engagement\Models\Review;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Minimal course reviews. A student who has access to a course may leave one
 * rating+comment (upserted). Public listing feeds the course page; the landing
 * `testimonials` section resolves reviews server-side (see LandingResolver).
 */
class ReviewController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EnrollmentService $enrollments,
    ) {}

    /** Public: recent reviews for a published course. */
    public function index(Course $course): AnonymousResourceCollection
    {
        $reviews = Review::query()
            ->visible()                       // hidden/moderated reviews never show publicly
            ->where('course_id', $course->getKey())
            ->with(['user:id,name', 'course:id,title'])
            ->latest()
            ->paginate(20);

        return ReviewResource::collection($reviews);
    }

    /** Student: create or update their review of a course they have access to. */
    public function store(StoreReviewRequest $request, Course $course): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $user = $request->user();

        abort_unless(
            $this->enrollments->hasAccess($tenantId, $user->getKey(), $course),
            403,
            'Enroll in this course before reviewing it.'
        );

        $review = Review::updateOrCreate(
            ['course_id' => $course->getKey(), 'user_id' => $user->getKey()],
            ['tenant_id' => $tenantId, 'rating' => $request->integer('rating'), 'comment' => $request->input('comment')],
        );

        return (new ReviewResource($review->load(['user:id,name', 'course:id,title'])))
            ->response()->setStatusCode(201);
    }
}
