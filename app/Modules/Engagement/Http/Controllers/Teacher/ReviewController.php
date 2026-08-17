<?php

namespace App\Modules\Engagement\Http\Controllers\Teacher;

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
 * (`author_name`, no student account). Reviews target a lesson or package
 * (`target_type`/`target_id`, VD §7 — `courses` retired). Every row is
 * tenant-scoped by `BelongsToTenant`, so a review id from another tenant → 404.
 */
class ReviewController
{
    /** List every review in the tenant (any target, any visibility), newest first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $reviews = Review::query()
            ->when(
                $request->filled('target_type') && $request->filled('target_id'),
                fn ($q) => $q->forTarget((string) $request->string('target_type'), $request->integer('target_id')),
            )
            ->when($request->filled('rating'), fn ($q) => $q->where('rating', $request->integer('rating')))
            ->when($request->filled('visible'), fn ($q) => $q->where('is_visible', $request->boolean('visible')))
            ->when($request->input('q'), fn ($q, $term) => $q->where('comment', 'like', '%'.$term.'%'))
            ->with('user:id,name')
            ->latest()
            ->paginate(20);

        return ReviewResource::collection($reviews);
    }

    /** Author a curated testimonial for one of the teacher's own lessons/packages. */
    public function store(TeacherReviewRequest $request): JsonResponse
    {
        $review = new Review($request->validated()); // target_type/target_id validated tenant-scoped
        $review->user_id = null; // teacher-authored — not tied to a student account
        $review->save();

        return (new ReviewResource($review))->response()->setStatusCode(201);
    }

    public function show(Review $review): ReviewResource
    {
        return new ReviewResource($review->load('user:id,name'));
    }

    /** Update any review in the tenant — moderate a student review or edit a testimonial. */
    public function update(TeacherReviewRequest $request, Review $review): ReviewResource
    {
        $review->update($request->validated());

        return new ReviewResource($review->load('user:id,name'));
    }

    public function destroy(Review $review): Response
    {
        $review->delete();

        return response()->noContent();
    }
}
