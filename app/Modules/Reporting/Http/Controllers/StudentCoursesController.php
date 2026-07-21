<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Commerce\Models\Enrollment;
use App\Modules\Engagement\Models\LessonProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /me/courses (M10) — the student's purchased/available courses with a
 * progress summary. Scoped to the current tenant via BelongsToTenant. Includes
 * courses reached through a package's unit or lesson grant, not only whole-course
 * buys.
 */
class StudentCoursesController
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->getKey();

        $grants = Enrollment::query()
            ->where('user_id', $userId)
            ->grantsAccess()
            ->get(['course_id', 'unit_id', 'lesson_id']);

        // Whole-course grants + parent courses of any unit/lesson (package) grants.
        $unitCourseIds = Unit::query()
            ->whereIn('id', $grants->pluck('unit_id')->filter()->unique())
            ->pluck('course_id');

        $lessonCourseIds = Lesson::query()
            ->whereIn('id', $grants->pluck('lesson_id')->filter()->unique())
            ->pluck('course_id');

        $courseIds = $grants->pluck('course_id')->filter()
            ->merge($unitCourseIds)
            ->merge($lessonCourseIds)
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

            $precent = LessonProgress::query()
                ->where('user_id', $userId)
                ->where('tenant_id', $course->tenant_id)
                ->whereIn('lesson_id', $course->lessons()->pluck('id'))
                ->first('watch_percent');

            return [
                'uuid' => $course->uuid,
                'title' => $course->title,
                'slug' => $course->slug,
                'cover_url' => $course->cover_url,
                'lessons_total' => $total,
                'lessons_completed' => $completed,
                'watch_precent' => $precent ? $precent->watch_percent : 0,
                'progress_percent' => $total > 0 ? (int) round($completed / $total * 100) : 0,
            ];
        });

        return response()->json(['data' => $data]);
    }
}
