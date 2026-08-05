<?php

namespace App\Modules\Catalog\Http\Controllers\Teacher;

use App\Modules\Catalog\Enums\AccessMode;
use App\Modules\Catalog\Http\Requests\LessonRequest;
use App\Modules\Catalog\Http\Resources\LessonResource;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Services\LessonAccessModeGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * /teacher/lessons (VD change set §8.3) — standalone lesson authoring. Scoped to
 * the active academic year by the `academic-year` middleware + the
 * BelongsToAcademicYear global scope, so a lesson from another year (or tenant)
 * simply isn't found → 404.
 */
class LessonController
{
    public function __construct(private readonly LessonAccessModeGuard $guard) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $lessons = Lesson::query()
            ->when(
                $request->filled('access_mode'),
                fn ($q) => $q->where('access_mode', $request->string('access_mode')),
            )
            ->when(
                $request->filled('search'),
                fn ($q) => $q->where('title', 'like', '%'.$request->string('search').'%'),
            )
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return LessonResource::collection($lessons);
    }

    public function store(LessonRequest $request): JsonResponse
    {
        // tenant_id + academic_year_id are auto-filled by the model traits.
        $lesson = Lesson::create($request->lessonAttributes());

        return (new LessonResource($lesson->fresh()->load('sections')))
            ->response()->setStatusCode(201);
    }

    public function show(Lesson $lesson): LessonResource
    {
        return new LessonResource($lesson->load([
            'sections' => fn ($q) => $q->ordered()->with(['mediaAsset', 'exam']),
            'academicYear', 'videoAsset', 'attachments',
        ]));
    }

    public function update(LessonRequest $request, Lesson $lesson): LessonResource
    {
        $attributes = $request->lessonAttributes();

        // Narrowing the lesson's channel must not orphan any wider existing part.
        if (array_key_exists('access_mode', $attributes)) {
            $this->guard->assertLessonNarrowingAllowed($lesson, AccessMode::from($attributes['access_mode']));
        }

        $lesson->update($attributes);

        return new LessonResource(
            $lesson->load(['sections' => fn ($q) => $q->ordered()->with(['mediaAsset', 'exam'])]),
        );
    }

    public function destroy(Lesson $lesson): Response
    {
        // Sections cascade via the FK; package_items auto-detach in Phase 5 (VD-D1c).
        $lesson->delete();

        return response()->noContent();
    }
}
