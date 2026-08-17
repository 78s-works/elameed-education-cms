<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Catalog\Models\Lesson;
use App\Modules\Commerce\Models\Enrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /me/courses (M10) — the student's owned lessons. Scoped to the current
 * tenant via BelongsToTenant. Lists every lesson the student has access to via an
 * access-granting enrollment (direct lesson buy or a package's lesson fan-out).
 */
class StudentCoursesController
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->getKey();

        $lessonIds = Enrollment::query()
            ->where('user_id', $userId)
            ->grantsAccess()
            ->whereNotNull('lesson_id')
            ->pluck('lesson_id')
            ->unique();

        $data = Lesson::query()
            ->whereIn('id', $lessonIds)
            ->get()
            ->map(fn (Lesson $lesson): array => [
                'id' => $lesson->id,
                'title' => $lesson->title,
                'access_mode' => $lesson->access_mode?->value,
            ]);

        return response()->json(['data' => $data]);
    }
}
