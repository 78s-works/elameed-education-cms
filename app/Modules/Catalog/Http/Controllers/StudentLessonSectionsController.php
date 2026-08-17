<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Http\Resources\LessonSectionResource;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Services\ContentUnlockService;
use App\Modules\Catalog\Services\LessonProgressionService;
use App\Modules\Catalog\Services\StudentPartVisibility;
use App\Modules\Commerce\Services\EnrollmentService;
use App\Modules\Engagement\Models\LessonProgress;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * GET /lessons/{lesson}/sections — the student's view of a lesson's typed
 * content sections, each stamped with its computed `locked` state from the
 * mandatory "Content Dependencies & Unlock Rules". Optional dependencies never
 * lock; they surface for the client to display. Parts outside the student's
 * study_mode channel (B12 / LP-6) are filtered out before the response.
 */
class StudentLessonSectionsController
{
    public function __construct(
        private readonly ContentUnlockService $unlock,
        private readonly EnrollmentService $enrollments,
        private readonly LessonProgressionService $progression,
        private readonly StudentPartVisibility $visibility,
        private readonly TenantContext $context,
    ) {}

    /**
     * GET /lessons/{lesson} — the lesson's own meta for the lesson-native player
     * (title, duration, completion, poster). Access-gated like `index`. Content
     * lives in the sibling `/sections` call; this is the header the player needs
     * without loading a parent course.
     */
    public function show(Request $request, Lesson $lesson): JsonResponse
    {
        $tenantId = (int) $this->context->tenantOrFail()->getKey();
        $userId = (int) $request->user()->getKey();

        if (! $lesson->is_free_preview && ! $this->enrollments->hasLessonAccess($tenantId, $userId, $lesson)) {
            abort(403, 'You do not have access to this lesson.');
        }

        $completed = LessonProgress::query()
            ->where('user_id', $userId)
            ->where('lesson_id', $lesson->id)
            ->whereNotNull('completed_at')
            ->exists();

        return response()->json([
            'data' => [
                'id' => $lesson->id,
                'title' => $lesson->title,
                'description' => $lesson->description,
                'duration_sec' => $lesson->duration_sec,
                'is_free_preview' => (bool) $lesson->is_free_preview,
                'access_mode' => $lesson->access_mode?->value,
                'completed' => $completed,
                'thumbnail_url' => null,
            ],
        ]);
    }

    public function index(Request $request, Lesson $lesson): AnonymousResourceCollection
    {
        $tenantId = (int) $this->context->tenantOrFail()->getKey();
        $userId = (int) $request->user()->getKey();

        if (! $lesson->is_free_preview && ! $this->enrollments->hasLessonAccess($tenantId, $userId, $lesson)) {
            abort(403, 'You do not have access to this lesson.');
        }

        // Progression gate (doc 11 R5): the previous lesson/unit must be cleared
        // before this lesson opens. 423 Locked carries the machine reason.
        $lock = $this->progression->progressionLock($tenantId, $userId, $lesson);
        if ($lock !== null) {
            throw new HttpException(423, $lock);
        }

        // Eager-load the backing exam so quiz/homework parts expose `exam.id`
        // (uuid) — the student player links its "Solve" action to it.
        $sections = $lesson->sections()->ordered()->with(['mediaAsset', 'exam'])->get();

        // B12 (LP-6): hide parts outside the student's study_mode channel — an
        // online student never sees center-only parts, and vice versa; `both`
        // parts and `both` students are unrestricted.
        $studyMode = $this->visibility->studyModeFor($tenantId, $userId);
        $sections = $this->visibility->filter($sections, $studyMode);

        $lockMap = $this->unlock->lockMap($tenantId, $userId, $lesson);

        foreach ($sections as $section) {
            $section->setAttribute('locked', $lockMap[(int) $section->id] ?? false);
            // Per-part result (VD F14 / LP-14): pass/fail, retake count, degree of
            // success. Null for parts with no backing exam.
            $section->setAttribute('part_result', $this->unlock->partResult($tenantId, $userId, $section));
        }

        return LessonSectionResource::collection($sections);
    }
}
