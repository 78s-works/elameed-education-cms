<?php

namespace App\Modules\Centers\Http\Controllers;

use App\Modules\Centers\Http\Resources\CenterExamGradeResource;
use App\Modules\Centers\Models\CenterExamGrade;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /me/center-exam-grades — the authenticated student's own paper (in-center)
 * exam scores (VD R12). Not behind the X-Academic-Year middleware, so it returns
 * grades across every year; still tenant-scoped by BelongsToTenant.
 */
class StudentCenterExamGradeController
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $grades = CenterExamGrade::query()
            ->where('student_user_id', $request->user()->getKey())
            ->with(['center', 'enteredBy:id,name'])
            ->latest('sat_on')
            ->get();

        return CenterExamGradeResource::collection($grades);
    }
}
