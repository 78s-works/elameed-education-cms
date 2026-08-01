<?php

namespace App\Modules\Assessment\Http\Controllers\Teacher;

use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Unit;
use Illuminate\Http\JsonResponse;

/**
 * Link-target options for the sidebar exam editor's dropdowns. A lesson_quiz /
 * homework links to a lesson; a unit_exam links to a unit. Both lists carry their
 * parent course (+ unit) titles so the picker can group them. Tenant-scoped by the
 * models' BelongsToTenant global scope.
 */
class ExamLinkController
{
    /** Every lesson, with its unit + course context, for the lesson picker. */
    public function lessons(): JsonResponse
    {
        $lessons = Lesson::query()
            ->with(['unit:id,title', 'course:id,title'])
            ->orderBy('course_id')->orderBy('unit_id')->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'title', 'unit_id', 'course_id']);

        return response()->json(['data' => $lessons->map(fn (Lesson $l) => [
            'id' => $l->id,
            'title' => $l->title,
            'unit_id' => $l->unit_id,
            'unit_title' => $l->unit?->title,
            'course_id' => $l->course_id,
            'course_title' => $l->course?->title,
        ])->values()]);
    }

    /** Every unit, with its course context, for the unit picker. */
    public function units(): JsonResponse
    {
        $units = Unit::query()
            ->with(['course:id,title'])
            ->orderBy('course_id')->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'title', 'course_id']);

        return response()->json(['data' => $units->map(fn (Unit $u) => [
            'id' => $u->id,
            'title' => $u->title,
            'course_id' => $u->course_id,
            'course_title' => $u->course?->title,
        ])->values()]);
    }
}
