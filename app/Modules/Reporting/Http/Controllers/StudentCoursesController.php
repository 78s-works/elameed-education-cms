<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Catalog\Models\Course;
use App\Modules\Commerce\Models\Enrollment;
use App\Modules\Engagement\Models\LessonProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /me/courses (M10) — the student's purchased/available courses with a
 * progress summary. Scoped to the current tenant via BelongsToTenant.
 */
class StudentCoursesController
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->getKey();

        $courseIds = Enrollment::query()
            ->where('user_id', $userId)
            ->grantsAccess()
            ->whereNotNull('course_id')
            ->pluck('course_id')
            ->unique();

        $courses = Course::query()->whereIn('id', $courseIds)->withCount('lessons')->get();

        $data = $courses->map(function (Course $course) use ($userId) {
            $completed = LessonProgress::query()
                ->where('user_id', $userId)
                ->where('tenant_id', $course->tenant_id)
                ->whereNotNull('completed_at')
                ->whereIn('lesson_id', $course->lessons()->pluck('id'))
                ->count();

            $total = (int) $course->lessons_count;

            return [
                'uuid' => $course->uuid,
                'title' => $course->title,
                'slug' => $course->slug,
                'cover_url' => $course->cover_url,
                'lessons_total' => $total,
                'lessons_completed' => $completed,
                'progress_percent' => $total > 0 ? (int) round($completed / $total * 100) : 0,
            ];
        });

        return response()->json(['data' => $data]);
    }
}
