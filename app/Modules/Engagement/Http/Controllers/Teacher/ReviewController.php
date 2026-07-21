<?php

namespace App\Modules\Engagement\Http\Controllers\Teacher;

use App\Modules\Catalog\Models\Course;
use App\Modules\Engagement\Http\Requests\TeacherReviewRequest;
use App\Modules\Engagement\Http\Resources\ReviewResource;
use App\Modules\Engagement\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Teacher-panel CRUD for the tenant's reviews / landing testimonials
 * (docs/api/engagement.md). The teacher can moderate student-submitted reviews
 * (hide/show via `is_visible`, edit, delete) AND author curated testimonials
 * (`author_name`, no student account). Every row is tenant-scoped by the
 * `BelongsToTenant` global scope, so a review id from another tenant → 404.
 */
class ReviewController
{
    /** List every review in the tenant (any course, any visibility), newest first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $reviews = Review::query()
            ->when($request->integer('course_id'), fn ($q, $id) => $q->where('course_id', $id))
            ->when($request->filled('rating'), fn ($q) => $q->where('rating', $request->integer('rating')))
            ->when($request->filled('visible'), fn ($q) => $q->where('is_visible', $request->boolean('visible')))
            ->when($request->input('q'), fn ($q, $term) => $q->where('comment', 'like', '%'.$term.'%'))
            ->with(['user:id,name', 'course:id,title'])
            ->latest()
            ->paginate(20);

        return ReviewResource::collection($reviews);
    }

    /** Author a curated testimonial for one of the teacher's own courses. */
    public function store(TeacherReviewRequest $request): JsonResponse
    {
        $course = $this->courseInTenant($request->integer('course_id'));

        $review = new Review($request->validated());
        $review->course_id = $course->id;
        $review->user_id = null; // teacher-authored — not tied to a student account
        $review->save();

        return (new ReviewResource($review->load('course:id,title')))
            ->response()->setStatusCode(201);
    }

    public function show(Review $review): ReviewResource
    {
        return new ReviewResource($review->load(['user:id,name', 'course:id,title']));
    }

    /** Update any review in the tenant — moderate a student review or edit a testimonial. */
    public function update(TeacherReviewRequest $request, Review $review): ReviewResource
    {
        $review->update($request->validated());

        return new ReviewResource($review->load(['user:id,name', 'course:id,title']));
    }

    public function destroy(Review $review): Response
    {
        $review->delete();

        return response()->noContent();
    }

    /** Resolve a course id to one owned by the current tenant, else 404. */
    private function courseInTenant(int $courseId): Course
    {
        $course = Course::find($courseId); // BelongsToTenant global scope → tenant-only

        abort_if($course === null, 404, 'Course not found in this tenant.');

        return $course;
    }
}
