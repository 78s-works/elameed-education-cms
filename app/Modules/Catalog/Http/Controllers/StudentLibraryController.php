<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Http\Resources\PackageResource;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Package;
use App\Modules\Commerce\Models\Enrollment;
use App\Modules\Engagement\Models\LessonProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The student's own library (VD F1) — the standalone lessons and packages they
 * have bought. Access is always granted per-lesson (enrollment `lesson_id`); a
 * package buy fans out into per-lesson rows that carry the source `package_id` as
 * provenance, so "bought packages" = the distinct `package_id`s on the student's
 * access-granting rows. Tenant isolation is the BelongsToTenant global scope.
 */
class StudentLibraryController
{
    /**
     * GET /me/lessons — the student's purchased standalone lessons. Each row
     * carries the parent course slug so the SPA can open the existing course
     * player (standalone lessons have no player of their own), plus a watched
     * flag from lesson_progress.
     */
    public function lessons(Request $request): JsonResponse
    {
        $userId = $request->user()->getKey();

        $lessonIds = Enrollment::query()
            ->where('user_id', $userId)
            ->grantsAccess()
            ->whereNotNull('lesson_id')
            ->pluck('lesson_id')
            ->unique()
            ->all();

        $lessons = Lesson::query()
            ->whereIn('id', $lessonIds)
            ->with('course:id,slug,title')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $completed = LessonProgress::query()
            ->where('user_id', $userId)
            ->whereIn('lesson_id', $lessonIds)
            ->whereNotNull('completed_at')
            ->pluck('lesson_id')
            ->all();
        $completedSet = array_flip($completed);

        $data = $lessons->map(fn (Lesson $l) => [
            'id' => $l->id,
            'name' => $l->title,
            'title' => $l->title,
            'access_mode' => $l->access_mode?->value,
            'price_minor' => $l->price_minor,
            'currency' => $l->currency,
            'course_id' => $l->course_id,
            'course_slug' => $l->course?->slug,
            'course_title' => $l->course?->title,
            'completed' => isset($completedSet[$l->id]),
        ])->all();

        return response()->json(['data' => $data]);
    }

    /** GET /me/packages — the packages the student has bought (by grant provenance). */
    public function packages(Request $request): AnonymousResourceCollection
    {
        $packageIds = Enrollment::query()
            ->where('user_id', $request->user()->getKey())
            ->grantsAccess()
            ->whereNotNull('package_id')
            ->pluck('package_id')
            ->unique()
            ->all();

        $packages = Package::query()
            ->whereIn('id', $packageIds)
            ->withCount('items')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(20);

        return PackageResource::collection($packages);
    }
}
