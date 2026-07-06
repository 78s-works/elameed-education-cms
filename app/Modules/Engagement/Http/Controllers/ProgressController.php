<?php

namespace App\Modules\Engagement\Http\Controllers;

use App\Modules\Catalog\Models\Lesson;
use App\Modules\Commerce\Services\EnrollmentService;
use App\Modules\Engagement\Http\Requests\ProgressRequest;
use App\Modules\Engagement\Models\LessonProgress;
use App\Modules\Engagement\Services\PointsService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Lesson progress (M10, M20). Powers resume, watch %, and the follow-up worklist.
 * Reporting requires access to the lesson (enrollment or free preview).
 */
class ProgressController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EnrollmentService $enrollments,
        private readonly PointsService $points,
    ) {}

    public function store(ProgressRequest $request, Lesson $lesson): JsonResponse
    {
        $user = $request->user();
        $tenantId = $this->context->tenantOrFail()->getKey();

        if (! $lesson->is_free_preview
            && ! $this->enrollments->hasAccess($tenantId, $user->getKey(), $lesson->course)) {
            throw new AccessDeniedHttpException('You do not have access to this lesson.');
        }

        $percent = (int) $request->validated('watch_percent');

        $progress = LessonProgress::query()->firstOrNew([
            'user_id' => $user->getKey(),
            'lesson_id' => $lesson->getKey(),
        ]);

        // Never regress the furthest-watched percentage.
        $progress->watch_percent = max((int) $progress->watch_percent, $percent);
        $progress->watch_seconds = max((int) $progress->watch_seconds, (int) $request->input('watch_seconds', 0));
        $progress->last_position_sec = (int) $request->input('last_position_sec', $progress->last_position_sec ?? 0);
        $progress->sessions_count = (int) $progress->sessions_count + 1;
        $justCompleted = $progress->watch_percent >= 95 && $progress->completed_at === null;
        if ($justCompleted) {
            $progress->completed_at = now();
        }
        $progress->save();

        if ($justCompleted) {
            $this->points->award($tenantId, $user->getKey(), (int) config('gamification.lesson_points', 5),
                'lesson.completed', 'lesson', $lesson->getKey());
        }

        return response()->json(['data' => [
            'watch_percent' => $progress->watch_percent,
            'last_position_sec' => $progress->last_position_sec,
            'completed' => $progress->completed_at !== null,
        ]]);
    }

    public function activity(Request $request): JsonResponse
    {
        $items = LessonProgress::query()
            ->where('user_id', $request->user()->getKey())
            ->with('lesson:id,title,course_id')
            ->latest('updated_at')
            ->limit(50)
            ->get()
            ->map(fn ($p) => [
                'lesson_id' => $p->lesson_id,
                'lesson_title' => $p->lesson?->title,
                'watch_percent' => $p->watch_percent,
                'last_position_sec' => $p->last_position_sec,
                'completed' => $p->completed_at !== null,
            ]);

        return response()->json(['data' => $items]);
    }

    /** "Continue watching" — lessons started but not finished, most recent first. */
    public function resume(Request $request): JsonResponse
    {
        $items = LessonProgress::query()
            ->where('user_id', $request->user()->getKey())
            ->whereNull('completed_at')
            ->where('watch_percent', '>', 0)
            ->with('lesson:id,title,course_id')
            ->latest('updated_at')
            ->limit(20)
            ->get()
            ->map(fn ($p) => [
                'lesson_id' => $p->lesson_id,
                'lesson_title' => $p->lesson?->title,
                'course_id' => $p->lesson?->course_id,
                'watch_percent' => $p->watch_percent,
                'last_position_sec' => $p->last_position_sec,
            ]);

        return response()->json(['data' => $items]);
    }
}
