<?php

namespace App\Modules\Assessment\Http\Controllers\Teacher;

use App\Modules\Assessment\Http\Requests\ExamRequest;
use App\Modules\Assessment\Http\Resources\ExamResource;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Catalog\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * A unit's optional exam (doc 11 R2 — "Unit can have an exam"). A unit has at
 * most one exam; questions are authored with the normal
 * /teacher/exams/{exam}/questions endpoints ("questions on website"). This exam
 * drives the progression gate R5.3 (the next unit's first lesson is blocked
 * until it is answered). {unit} binds by id and is tenant-scoped.
 */
class UnitExamController
{
    public function show(Unit $unit): JsonResponse
    {
        $exam = $this->examFor($unit);

        return response()->json(['data' => $exam ? new ExamResource($exam->loadCount('questions')) : null]);
    }

    public function store(ExamRequest $request, Unit $unit): JsonResponse
    {
        abort_if($this->examFor($unit) !== null, 409, 'This unit already has an exam.');

        $exam = new Exam($request->validated());
        $exam->course_id = $unit->course_id;
        $exam->unit_id = $unit->id;
        $exam->save(); // BelongsToTenant fills tenant_id

        return (new ExamResource($exam))->response()->setStatusCode(201);
    }

    public function update(ExamRequest $request, Unit $unit): ExamResource
    {
        $exam = $this->examFor($unit);
        abort_if($exam === null, 404, 'This unit has no exam.');

        $exam->update($request->validated());

        return new ExamResource($exam->loadCount('questions'));
    }

    public function destroy(Unit $unit): Response
    {
        $exam = $this->examFor($unit);
        abort_if($exam === null, 404, 'This unit has no exam.');

        $exam->delete(); // soft delete (keeps attempt history)

        return response()->noContent();
    }

    private function examFor(Unit $unit): ?Exam
    {
        return Exam::query()->where('unit_id', $unit->id)->first();
    }
}
