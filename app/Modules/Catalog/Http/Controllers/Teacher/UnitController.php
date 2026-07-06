<?php

namespace App\Modules\Catalog\Http\Controllers\Teacher;

use App\Modules\Catalog\Http\Requests\UnitRequest;
use App\Modules\Catalog\Http\Resources\UnitResource;
use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * /teacher/courses/{course}/units (FR-M04-02). Both models are tenant-scoped by
 * binding; we also assert the unit belongs to the course in the URL.
 */
class UnitController
{
    public function index(Course $course): AnonymousResourceCollection
    {
        return UnitResource::collection($course->units()->orderBy('sort_order')->get());
    }

    public function store(UnitRequest $request, Course $course): JsonResponse
    {
        $unit = $course->units()->create($request->validated());

        return (new UnitResource($unit))->response()->setStatusCode(201);
    }

    public function update(UnitRequest $request, Course $course, Unit $unit): UnitResource
    {
        $this->assertOwnership($course, $unit);
        $unit->update($request->validated());

        return new UnitResource($unit);
    }

    public function destroy(Course $course, Unit $unit): Response
    {
        $this->assertOwnership($course, $unit);
        $unit->delete();

        return response()->noContent();
    }

    private function assertOwnership(Course $course, Unit $unit): void
    {
        abort_unless($unit->course_id === $course->id, 404);
    }
}
