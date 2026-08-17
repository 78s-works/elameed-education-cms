<?php

namespace App\Modules\Assessment\Http\Controllers\Teacher;

use App\Modules\Catalog\Models\Lesson;
use Illuminate\Http\JsonResponse;

/**
 * Link-target options for the sidebar exam editor's dropdowns. A lesson_quiz /
 * homework links to a lesson. Tenant-scoped by the model's BelongsToTenant global
 * scope. (`courses`/units retired — VD §7.)
 */
class ExamLinkController
{
    /** Every lesson, for the lesson picker. */
    public function lessons(): JsonResponse
    {
        $lessons = Lesson::query()
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'title']);

        return response()->json(['data' => $lessons->map(fn (Lesson $l) => [
            'id' => $l->id,
            'title' => $l->title,
        ])->values()]);
    }
}
