<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Assessment\Models\ExamAttempt;
use App\Modules\Catalog\Enums\AssignmentKind;
use App\Modules\Catalog\Enums\LessonSectionType;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\LessonSection;
use App\Modules\Catalog\Models\Unit;

/**
 * Lesson progression gate (doc 11 R5) — decides whether a student may OPEN a
 * lesson, given the completion state of the PREVIOUS lesson / unit. This is the
 * cross-lesson / cross-unit sequencing that `ContentUnlockService` (which only
 * gates sections WITHIN a lesson) does not cover.
 *
 * Rules enforced here (only when the part is `is_required`):
 *   R5.2 — the previous lesson's required homework-upload must be submitted AND
 *          graded/corrected (exam_attempts.status = graded).
 *   R5.3 — if this lesson is the FIRST in its unit and the PREVIOUS unit has a
 *          published exam, that exam must be answered (an attempt submitted).
 *
 * R5.1 (the review quiz must be answered) is enforced WITHIN the lesson by
 * ContentUnlockService: the lesson body sections depend on the entry quiz. See
 * decision D1 in doc 11.
 *
 * Access-critical: explicit tenant id, queries run withoutGlobalScopes
 * (mirrors EnrollmentService / ContentUnlockService).
 */
class LessonProgressionService
{
    /**
     * @return string|null null = the lesson may be opened; otherwise a machine
     *                     lock reason (prev_homework_missing |
     *                     prev_homework_uncorrected | prev_unit_exam_missing).
     */
    public function progressionLock(int $tenantId, int $userId, Lesson $lesson): ?string
    {
        if ($lesson->is_free_preview) {
            return null;
        }

        $previous = $this->previousLesson($tenantId, $lesson);

        if ($previous !== null) {
            return $this->homeworkLock($tenantId, $userId, $previous);          // R5.2
        }

        return $this->previousUnitExamLock($tenantId, $userId, $lesson);        // R5.3
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

    /** The unit immediately before this lesson's unit in the same course. */
    public function previousUnit(int $tenantId, Lesson $lesson): ?Unit
    {
        $unit = Unit::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($lesson->unit_id);
        if ($unit === null) {
            return null;
        }

        return Unit::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('course_id', $unit->course_id)
            ->where(function ($q) use ($unit): void {
                $q->where('sort_order', '<', $unit->sort_order)
                    ->orWhere(fn ($q2) => $q2->where('sort_order', $unit->sort_order)->where('id', '<', $unit->id));
            })
            ->orderByDesc('sort_order')
            ->orderByDesc('id')
            ->first();
    }

    /** R5.2 — every required homework-upload of $previous must be submitted and graded. */
    private function homeworkLock(int $tenantId, int $userId, Lesson $previous): ?string
    {
        $homeworks = LessonSection::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('lesson_id', $previous->getKey())
            ->where('type', LessonSectionType::Assignment->value)
            ->where('assignment_kind', AssignmentKind::Upload->value)
            ->where('is_required', true)
            ->whereNotNull('exam_id')
            ->get();

        foreach ($homeworks as $homework) {
            $attempts = ExamAttempt::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('exam_id', (int) $homework->exam_id)
                ->where('user_id', $userId)
                ->get();

            if ($attempts->whereNotNull('submitted_at')->isEmpty()) {
                return 'prev_homework_missing';
            }

            if ($attempts->where('status', 'graded')->isEmpty()) {
                return 'prev_homework_uncorrected';
            }
        }

        return null;
    }

    /** R5.3 — first lesson of the unit is blocked until the previous unit's exam is answered. */
    private function previousUnitExamLock(int $tenantId, int $userId, Lesson $lesson): ?string
    {
        $previousUnit = $this->previousUnit($tenantId, $lesson);
        if ($previousUnit === null) {
            return null; // first unit — nothing upstream
        }

        $exam = \App\Modules\Assessment\Models\Exam::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('unit_id', $previousUnit->getKey())
            ->where('is_published', true)
            ->first();

        if ($exam === null) {
            return null; // previous unit has no exam
        }

        $answered = ExamAttempt::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('exam_id', $exam->getKey())
            ->where('user_id', $userId)
            ->whereNotNull('submitted_at')
            ->exists();

        return $answered ? null : 'prev_unit_exam_missing';
    }
}
