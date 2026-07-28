<?php

namespace App\Modules\Catalog\Http\Controllers\Teacher;

use App\Modules\Catalog\Http\Requests\ContentDependencyRequest;
use App\Modules\Catalog\Http\Resources\ContentDependencyResource;
use App\Modules\Catalog\Models\ContentDependency;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\LessonSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * /teacher/lessons/{lesson}/sections/{section}/dependencies ("Content
 * Dependencies & Unlock Rules"). Manages the unlock rules that gate one section
 * behind another. Prerequisite must be a sibling section of the same lesson.
 */
class ContentDependencyController
{
    public function index(Lesson $lesson, LessonSection $section): AnonymousResourceCollection
    {
        $this->assertOwnership($lesson, $section);

        return ContentDependencyResource::collection($section->dependencies()->get());
    }

    public function store(ContentDependencyRequest $request, Lesson $lesson, LessonSection $section): JsonResponse
    {
        $this->assertOwnership($lesson, $section);

        $data = $request->validated();
        $dependsOn = (int) $data['depends_on_section_id'];

        abort_if($dependsOn === $section->id, 422, 'A section cannot depend on itself.');

        $prereq = LessonSection::where('lesson_id', $lesson->id)->find($dependsOn);
        abort_if($prereq === null, 422, 'The prerequisite must be a section of the same lesson.');

        $exists = $section->dependencies()->where('depends_on_section_id', $dependsOn)->exists();
        abort_if($exists, 422, 'This dependency already exists.');

        $dependency = $section->dependencies()->create($data);

        return (new ContentDependencyResource($dependency))->response()->setStatusCode(201);
    }

    public function destroy(Lesson $lesson, LessonSection $section, ContentDependency $dependency): Response
    {
        $this->assertOwnership($lesson, $section);
        abort_unless($dependency->section_id === $section->id, 404);

        $dependency->delete();

        return response()->noContent();
    }

    private function assertOwnership(Lesson $lesson, LessonSection $section): void
    {
        abort_unless($section->lesson_id === $lesson->id, 404);
    }
}
