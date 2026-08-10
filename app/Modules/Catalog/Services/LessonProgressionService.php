<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Assessment\Enums\ExamType;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Assessment\Models\ExamAttempt;
use App\Modules\Catalog\Models\Lesson;

/**
 * Lesson progression gate (convention model) — decides whether a student may OPEN
 * a lesson, from the completion state of the PREVIOUS lesson in the same unit.
 *
 * The ONE rule, automatic and unconfigurable:
 *   - The FIRST lesson of every unit is always open (no previous lesson).
 *   - Any later lesson unlocks once the previous lesson's lesson_quiz AND homework
 *     have each been SUBMITTED (grading is NOT required). A missing quiz/homework
 *     on the previous lesson simply doesn't gate.
 *
 * There is no cross-unit gate and no unit-exam gate. Exams themselves are never
 * locked — this gate only sequences lesson (video) content. Solution videos
 * within a lesson are gated separately by ContentUnlockService.
 *
 * A staff-granted access override (ContentAccessOverride) on the lesson or its
 * unit short-circuits the gate.
 *
 * Access-critical: explicit tenant id, queries run withoutGlobalScopes
 * (mirrors EnrollmentService / ContentUnlockService).
 */
class LessonProgressionService
{
    public function __construct(
        private readonly ContentAccessOverrideService $overrides,
        private readonly SequentialUnlockService $sequential,
    ) {}

    /**
     * @return string|null null = the lesson may be opened; otherwise a machine lock
     *                     reason (prev_lesson_incomplete | prev_quiz_missing |
     *                     prev_homework_missing).
     */
    public function progressionLock(int $tenantId, int $userId, Lesson $lesson): ?string
    {
        if ($lesson->is_free_preview) {
            return null;
        }

        // A staff-granted override on this lesson (or its unit) opens it outright.
        if ($this->overrides->hasActiveForLesson($tenantId, $userId, $lesson)) {
            return null;
        }

        // Sequential package unlock (B14 / VD R5): a lesson bought as part of a
        // package stays locked until the PREVIOUS lesson in that package's ordered
        // sequence is completed. No-op for lessons not sourced from a package.
        $sequenceLock = $this->sequential->sequenceLock($tenantId, $userId, $lesson);
        if ($sequenceLock !== null) {
            return $sequenceLock;
        }

        $previous = $this->previousLesson($tenantId, $lesson);

        // First lesson of the unit (or a unit-less lesson) — always open.
        if ($previous === null) {
            return null;
        }

        return $this->previousLessonGate($tenantId, $userId, $previous);
    }

    /** Convenience boolean. */
    public function canOpen(int $tenantId, int $userId, Lesson $lesson): bool
    {
        return $this->progressionLock($tenantId, $userId, $lesson) === null;
    }

    /** The lesson immediately before this one in the same unit (by sort_order, id). */
    public function previousLesson(int $tenantId, Lesson $lesson): ?Lesson
    {
        if ($lesson->unit_id === null) {
            return null;
        }

        return Lesson::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('unit_id', $lesson->unit_id)
            ->where(function ($q) use ($lesson): void {
                $q->where('sort_order', '<', $lesson->sort_order)
                    ->orWhere(fn ($q2) => $q2->where('sort_order', $lesson->sort_order)->where('id', '<', $lesson->id));
            })
            ->orderByDesc('sort_order')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The previous lesson's lesson_quiz AND homework must each be submitted (when
     * that lesson has one). A published exam with no submitted attempt gates.
     */
    private function previousLessonGate(int $tenantId, int $userId, Lesson $previous): ?string
    {
        if ($this->hasUnsubmittedExam($tenantId, $userId, $previous, ExamType::LessonQuiz)) {
            return 'prev_quiz_missing';
        }

        if ($this->hasUnsubmittedExam($tenantId, $userId, $previous, ExamType::Homework)) {
            return 'prev_homework_missing';
        }

        return null;
    }

    /**
     * Does the lesson have a published exam of $type that the user has NOT yet
     * submitted? False when the lesson has no such (published) exam — nothing to gate.
     */
    private function hasUnsubmittedExam(int $tenantId, int $userId, Lesson $lesson, ExamType $type): bool
    {
        $exam = Exam::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('lesson_id', $lesson->getKey())
            ->where('type', $type->value)
            ->where('is_published', true)
            ->first();

        if ($exam === null) {
            return false;
        }

        $submitted = ExamAttempt::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('exam_id', $exam->getKey())
            ->where('user_id', $userId)
            ->whereNotNull('submitted_at')
            ->exists();

        return ! $submitted;
    }
}
