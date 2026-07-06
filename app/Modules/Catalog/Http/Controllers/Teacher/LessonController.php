<?php

namespace App\Modules\Catalog\Http\Controllers\Teacher;

use App\Modules\Catalog\Http\Requests\LessonRequest;
use App\Modules\Catalog\Http\Resources\LessonResource;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * /teacher/units/{unit}/lessons (FR-M04-02). course_id is inherited from the
 * unit so a lesson always agrees with its unit's course.
 */
class LessonController
{
    public function index(Unit $unit): AnonymousResourceCollection
    {
        return LessonResource::collection(
            $unit->lessons()->with('attachments')->orderBy('sort_order')->get()
        );
    }

    public function store(LessonRequest $request, Unit $unit): JsonResponse
    {
        $lesson = $unit->lessons()->create(
            $request->validated() + ['course_id' => $unit->course_id]
        );

        return (new LessonResource($lesson))->response()->setStatusCode(201);
    }

    public function update(LessonRequest $request, Unit $unit, Lesson $lesson): LessonResource
    {
        $this->assertOwnership($unit, $lesson);
        $lesson->update($request->validated());

        return new LessonResource($lesson);
    }

    public function destroy(Unit $unit, Lesson $lesson): Response
    {
        $this->assertOwnership($unit, $lesson);
        $lesson->delete();

        return response()->noContent();
    }

    private function assertOwnership(Unit $unit, Lesson $lesson): void
    {
        abort_unless($lesson->unit_id === $unit->id, 404);
    }
}
