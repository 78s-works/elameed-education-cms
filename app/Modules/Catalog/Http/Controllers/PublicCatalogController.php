<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Http\Resources\CourseDetailResource;
use App\Modules\Catalog\Http\Resources\CourseResource;
use App\Modules\Catalog\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Public catalogue for the resolved tenant (GET /courses, /courses/{slug}).
 * Only published (visible + due) courses are returned; tenant isolation is via
 * the BelongsToTenant scope. No auth.
 */
class PublicCatalogController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $courses = Course::query()
            ->published()
            ->with('category')
            ->when($request->input('filter.category_id'), fn ($q, $id) => $q->where('category_id', $id))
            ->when($request->input('filter.grade'), fn ($q, $grade) => $q->whereHas('category', fn ($c) => $c->where('grade', $grade)))
            ->when($request->input('filter.subject'), fn ($q, $subject) => $q->whereHas('category', fn ($c) => $c->where('subject', $subject)))
            ->when($request->input('q'), fn ($q, $term) => $q->where('title', 'like', '%'.$term.'%'))
            ->latest()
            ->paginate(20);

        return CourseResource::collection($courses);
    }

    public function show(Course $course): CourseDetailResource
    {
        // Route binding scopes to the tenant; hidden/scheduled courses 404 publicly.
        abort_unless($course->isPublished(), 404);

        $course->load([
            'category',
            'units' => fn ($q) => $q->published()->orderBy('sort_order')
                ->with(['lessons' => fn ($l) => $l->published()->orderBy('sort_order')]),
        ]);

        return new CourseDetailResource($course);
    }
}
