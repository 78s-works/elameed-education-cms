<?php

namespace App\Modules\Assessment\Http\Controllers\Teacher;

use App\Modules\Catalog\Models\Lesson;
use Illuminate\Http\JsonResponse;

/**
 * Link-target options for the sidebar exam editor's dropdowns. A lesson_quiz /
 * homework links to a lesson. Tenant-scoped by the model's BelongsToTenant global
 * scope. (The unit picker is retired — Unit removed, VD §7; `unit_id` remains a
 * dormant scalar on lessons.)
 */
class ExamLinkController
{
    /** Every lesson, with its course context, for the lesson picker. */
    public function lessons(): JsonResponse
    {
        $lessons = Lesson::query()
            ->with(['course:id,title'])
            ->orderBy('course_id')->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'title', 'unit_id', 'course_id']);

        return response()->json(['data' => $lessons->map(fn (Lesson $l) => [
            'id' => $l->id,
            'title' => $l->title,
            'unit_id' => $l->unit_id,
            'course_id' => $l->course_id,
            'course_title' => $l->course?->title,
        ])->values()]);
    }
}
