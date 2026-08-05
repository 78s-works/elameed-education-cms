<?php

namespace App\Modules\Centers\Http\Controllers\Teacher;

use App\Models\User;
use App\Modules\Centers\Http\Requests\CenterExamGradeRequest;
use App\Modules\Centers\Http\Resources\CenterExamGradeResource;
use App\Modules\Centers\Models\Center;
use App\Modules\Centers\Models\CenterExamGrade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * /teacher/center-exam-grades (VD R12, doc 13 Phase 15) — record/list/edit
 * paper (in-center) exam scores. Gated role:teacher,assistant + permission:centers,
 * year-scoped by the X-Academic-Year middleware: index/show/update/delete only
 * touch grades of the active academic year (BelongsToAcademicYear scope), and
 * store stamps that year automatically.
 */
class CenterExamGradeController
{
    /** Filterable by ?student=<uuid> and ?center=<uuid>. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $grades = CenterExamGrade::query()
            ->with(['center', 'student:id,uuid,name,phone', 'enteredBy:id,name'])
            ->when($request->query('student'), fn ($q, $uuid) => $q->whereHas(
                'student',
                fn ($s) => $s->where('uuid', $uuid),
            ))
            ->when($request->query('center'), fn ($q, $uuid) => $q->whereHas(
                'center',
                fn ($c) => $c->where('uuid', $uuid),
            ))
            ->latest('sat_on')
            ->paginate(50);

        return CenterExamGradeResource::collection($grades);
    }

    public function store(CenterExamGradeRequest $request): JsonResponse
    {
        $grade = CenterExamGrade::create($this->attributes($request));

        return (new CenterExamGradeResource($grade->load(['center', 'student', 'enteredBy'])))
            ->response()->setStatusCode(201);
    }

    public function update(CenterExamGradeRequest $request, CenterExamGrade $grade): CenterExamGradeResource
    {
        $grade->update($this->attributes($request));

        return new CenterExamGradeResource($grade->load(['center', 'student', 'enteredBy']));
    }

    public function destroy(CenterExamGrade $grade): Response
    {
        $grade->delete();

        return response()->noContent();
    }

    /** Map the validated uuids to ids and stamp the staff author. */
    private function attributes(CenterExamGradeRequest $request): array
    {
        $data = $request->validated();

        return [
            'center_id' => Center::query()->where('uuid', $data['center'])->value('id'),
            'student_user_id' => User::query()->where('uuid', $data['student'])->value('id'),
            'title' => $data['title'],
            'total_marks' => $data['total_marks'],
            'score' => $data['score'],
            'sat_on' => $data['sat_on'],
            'note' => $data['note'] ?? null,
            'entered_by' => $request->user()->getKey(),
        ];
    }
}
