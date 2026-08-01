<?php

namespace App\Modules\Catalog\Http\Controllers\Teacher;

use App\Modules\Catalog\Http\Requests\LessonSectionRequest;
use App\Modules\Catalog\Http\Resources\LessonSectionResource;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\LessonSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * /teacher/lessons/{lesson}/sections (FR-M04-01, "Flexible Lesson Content
 * Structure"). CRUD for the ordered typed content sections of a lesson. Lesson
 * + section bind by id and are tenant-scoped, so cross-tenant ids 404.
 */
class LessonSectionController
{
    public function index(Lesson $lesson): AnonymousResourceCollection
    {
        return LessonSectionResource::collection(
            $lesson->sections()->ordered()->with(['mediaAsset'])->get()
        );
    }

    public function store(LessonSectionRequest $request, Lesson $lesson): JsonResponse
    {
        $section = $lesson->sections()->create($request->validated());

        return (new LessonSectionResource($section->load(['mediaAsset'])))
            ->response()->setStatusCode(201);
    }

    public function update(LessonSectionRequest $request, Lesson $lesson, LessonSection $section): LessonSectionResource
    {
        $this->assertOwnership($lesson, $section);
        $section->update($request->validated());

        return new LessonSectionResource($section->load(['mediaAsset']));
    }

    public function destroy(Lesson $lesson, LessonSection $section): Response
    {
        $this->assertOwnership($lesson, $section);
        $section->delete();

        return response()->noContent();
    }

    private function assertOwnership(Lesson $lesson, LessonSection $section): void
    {
        abort_unless($section->lesson_id === $lesson->id, 404);
    }
}
