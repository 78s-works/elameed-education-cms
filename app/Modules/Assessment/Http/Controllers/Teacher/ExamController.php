<?php

namespace App\Modules\Assessment\Http\Controllers\Teacher;

use App\Modules\Assessment\Http\Requests\ExamRequest;
use App\Modules\Assessment\Http\Resources\ExamResource;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Catalog\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Teacher authoring of exams (M08). Exams are created under a course; both are
 * tenant-scoped by route-model binding, so cross-tenant ids 404.
 */
class ExamController
{
    public function index(Course $course): AnonymousResourceCollection
    {
        return ExamResource::collection(
            Exam::query()->where('course_id', $course->id)->withCount('questions')->latest('id')->get()
        );
    }

    public function store(ExamRequest $request, Course $course): JsonResponse
    {
        $exam = new Exam($request->validated());
        $exam->course_id = $course->id;
        $exam->save(); // BelongsToTenant fills tenant_id

        return (new ExamResource($exam))->response()->setStatusCode(201);
    }

    public function show(Exam $exam): ExamResource
    {
        return new ExamResource($exam->loadCount('questions'));
    }

    public function update(ExamRequest $request, Exam $exam): ExamResource
    {
        $exam->update($request->validated());

        return new ExamResource($exam->loadCount('questions'));
    }

    public function destroy(Exam $exam): Response
    {
        $exam->delete(); // soft delete (keeps attempt history)

        return response()->noContent();
    }
}
