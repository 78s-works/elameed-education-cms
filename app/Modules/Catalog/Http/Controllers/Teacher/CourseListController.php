<?php

namespace App\Modules\Catalog\Http\Controllers\Teacher;

use App\Modules\Catalog\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only course list for the teacher panel (VD change set).
 *
 * The VD redesign retired teacher course/unit/bundle CRUD (authoring moved to
 * standalone lessons + recursive packages), but `courses` are still a live,
 * first-class concept: the public catalogue lists them, students review them,
 * and several teacher features still scope to a course — coupons
 * (`coupons.course_id`), curated reviews (`reviews.course_id`) and center
 * activation-codes (`activation_codes.course_id`). Those pickers need a source.
 *
 * This is that source and nothing more — a tenant-scoped lister (all
 * visibilities, since the teacher owns them) exposing the numeric `id` (needed
 * by course_id-typed endpoints) alongside the uuid. No create/update/delete.
 */
class CourseListController
{
    public function index(Request $request): JsonResponse
    {
        $courses = Course::query()
            ->when(
                $request->filled('q'),
                fn ($q) => $q->where('title', 'like', '%'.$request->string('q').'%'),
            )
            ->orderBy('title')
            ->orderBy('id')
            ->paginate((int) $request->integer('per_page', 100));

        $courses->getCollection()->transform(fn (Course $c) => [
            'id' => $c->id,
            'uuid' => $c->uuid,
            'title' => $c->title,
            'visibility' => $c->visibility?->value,
            'is_center' => (bool) $c->is_center,
            'price_minor' => $c->price_minor,
            'currency' => $c->currency,
        ]);

        return response()->json([
            'data' => $courses->items(),
            'meta' => [
                'current_page' => $courses->currentPage(),
                'last_page' => $courses->lastPage(),
                'per_page' => $courses->perPage(),
                'total' => $courses->total(),
            ],
        ]);
    }
}
