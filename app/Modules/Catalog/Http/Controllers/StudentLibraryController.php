<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Http\Resources\PackageResource;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Package;
use App\Modules\Catalog\Services\PackageItemService;
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
     * GET /me/lessons — the student's purchased standalone lessons (the lesson is
     * its own player entry now — `courses` retired, VD §7), plus a watched flag
     * from lesson_progress.
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
            'completed' => isset($completedSet[$l->id]),
        ])->all();

        return response()->json(['data' => $data]);
    }

    /**
     * GET /me/packages/{package}/lessons — the playable lessons a student owns
     * inside one bought package. Resolves the package's recursive descendant
     * lessons (walking package_items, any depth) and intersects them with the
     * student's access-granting enrollments, so the SPA can open a bought package
     * to its lessons (there is no other student-facing package-contents surface).
     * Each lesson is its own player entry (`courses` retired, VD §7) and carries a
     * completed flag. Lessons the student has not been granted are omitted.
     */
    public function packageLessons(Request $request, Package $package): JsonResponse
    {
        $userId = $request->user()->getKey();

        $descendantIds = app(PackageItemService::class)->descendantLessonIds($package);

        $ownedIds = Enrollment::query()
            ->where('user_id', $userId)
            ->grantsAccess()
            ->whereIn('lesson_id', $descendantIds)
            ->pluck('lesson_id')
            ->unique()
            ->all();

        $lessons = Lesson::query()
            ->whereIn('id', $ownedIds)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $completed = LessonProgress::query()
            ->where('user_id', $userId)
            ->whereIn('lesson_id', $ownedIds)
            ->whereNotNull('completed_at')
            ->pluck('lesson_id')
            ->all();
        $completedSet = array_flip($completed);

        return response()->json([
            'data' => [
                'package' => [
                    'id' => $package->id,
                    'uuid' => $package->uuid,
                    'name' => $package->name,
                    'access_mode' => $package->access_mode?->value,
                    'items_count' => (int) $descendantIds->count(),
                ],
                'lessons' => $lessons->map(fn (Lesson $l) => [
                    'id' => $l->id,
                    'name' => $l->title,
                    'title' => $l->title,
                    'access_mode' => $l->access_mode?->value,
                    'completed' => isset($completedSet[$l->id]),
                ])->all(),
            ],
        ]);
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
            // B27 package-type filter (by the type's public uuid).
            ->when($request->input('package_type'), fn ($q, $uuid) => $q->whereHas(
                'packageType',
                fn ($t) => $t->where('uuid', $uuid),
            ))
            ->with('packageType')
            ->withCount('items')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(20);

        return PackageResource::collection($packages);
    }
}
