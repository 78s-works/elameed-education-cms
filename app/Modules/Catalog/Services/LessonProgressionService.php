<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Assessment\Models\Exam;
use App\Modules\Assessment\Models\ExamAttempt;
use App\Modules\Catalog\Enums\AssignmentKind;
use App\Modules\Catalog\Enums\DependencyEnforcement;
use App\Modules\Catalog\Enums\DependencyTrigger;
use App\Modules\Catalog\Enums\LessonSectionType;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\LessonSection;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Catalog\Models\UnitDependency;

/**
 * Lesson progression gate (doc 11 R5) — decides whether a student may OPEN a
 * lesson, given the completion state of the PREVIOUS lesson / unit. This is the
 * cross-lesson / cross-unit sequencing that `ContentUnlockService` (which only
 * gates sections WITHIN a lesson) does not cover.
 *
 * Rules enforced here (only when the part is `is_required`):
 *   R5.2 — the previous lesson's required homework-upload must be submitted AND
 *          graded/corrected (exam_attempts.status = graded).
 *   R5.3 — if this lesson is the FIRST in its unit, its CONFIGURED unit
 *          prerequisites (unit_dependencies) must be satisfied. When none are
 *          configured this falls back to the default: the immediately previous
 *          unit's published exam must be answered.
 *
 * R5.1 (the review quiz must be answered) is enforced WITHIN the lesson by
 * ContentUnlockService: the lesson body sections depend on the entry quiz. See
 * decision D1 in doc 11.
 *
 * A staff-granted access override (ContentAccessOverride) on the lesson or its
 * unit short-circuits every gate here.
 *
 * Access-critical: explicit tenant id, queries run withoutGlobalScopes
 * (mirrors EnrollmentService / ContentUnlockService).
 */
class LessonProgressionService
{
    public function __construct(
        private readonly ContentAccessOverrideService $overrides,
        private readonly ContentUnlockService $unlock,
    ) {}

    /**
     * @return string|null null = the lesson may be opened; otherwise a machine
     *                     lock reason (prev_homework_missing |
     *                     prev_homework_uncorrected | prev_unit_exam_missing |
     *                     unit_prerequisite_unmet).
     */
    public function progressionLock(int $tenantId, int $userId, Lesson $lesson): ?string
    {
        if ($lesson->is_free_preview) {
            return null;
        }

        // A staff-granted override on this lesson (or its unit) opens it outright,
        // bypassing the progression gates below.
        if ($this->overrides->hasActiveForLesson($tenantId, $userId, $lesson)) {
            return null;
        }

        $previous = $this->previousLesson($tenantId, $lesson);

        if ($previous !== null) {
            return $this->homeworkLock($tenantId, $userId, $previous);          // R5.2
        }

        return $this->unitDependencyLock($tenantId, $userId, $lesson);          // R5.3
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

    /**
     * R5.3 (generalised) — gate the first lesson of a unit on its CONFIGURED unit
     * prerequisites (unit_dependencies). Falls back to the previous-unit-exam
     * default when the unit has no explicit mandatory rules.
     */
    private function unitDependencyLock(int $tenantId, int $userId, Lesson $lesson): ?string
    {
        if ($lesson->unit_id === null) {
            return null;
        }

        $deps = UnitDependency::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('unit_id', $lesson->unit_id)
            ->where('enforcement', DependencyEnforcement::Mandatory->value)
            ->get();

        if ($deps->isEmpty()) {
            return $this->previousUnitExamLock($tenantId, $userId, $lesson); // default R5.3
        }

        foreach ($deps as $dep) {
            if (! $this->unitDependencyMet($tenantId, $userId, $dep)) {
                return 'unit_prerequisite_unmet';
            }
        }

        return null;
    }

    /** Is one configured unit prerequisite satisfied for the user? */
    private function unitDependencyMet(int $tenantId, int $userId, UnitDependency $dep): bool
    {
        if ($dep->depends_on_section_id !== null) {
            $prereq = LessonSection::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->find($dep->depends_on_section_id);

            // Dangling prerequisite — treat as satisfied (mirrors ContentUnlockService).
            return $prereq === null
                || $this->unlock->sectionTriggerMet($tenantId, $userId, $prereq, $dep->trigger);
        }

        if ($dep->depends_on_unit_id !== null) {
            return $this->unitExamTriggerMet($tenantId, $userId, (int) $dep->depends_on_unit_id, $dep->trigger);
        }

        return true; // malformed row (no target) — never gates
    }

    /**
     * Evaluate a `trigger` against a prerequisite unit's published exam. A unit
     * with no published exam has nothing to satisfy, so it never gates.
     */
    private function unitExamTriggerMet(int $tenantId, int $userId, int $unitId, DependencyTrigger $trigger): bool
    {
        $exam = Exam::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('unit_id', $unitId)
            ->where('is_published', true)
            ->first();

        if ($exam === null) {
            return true;
        }

        $attempts = ExamAttempt::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('exam_id', $exam->getKey())
            ->where('user_id', $userId)
            ->whereNotNull('submitted_at')
            ->get();

        if ($attempts->isEmpty()) {
            return false;
        }

        return match ($trigger) {
            DependencyTrigger::Submitted, DependencyTrigger::Completed => true,
            DependencyTrigger::Graded => $attempts->firstWhere('status', 'graded') !== null,
            DependencyTrigger::Passed => $attempts->contains(
                fn (ExamAttempt $a) => (int) ($a->max_score ?? 0) > 0
                    && ((int) ($a->score ?? 0)) / (int) $a->max_score * 100 >= (int) ($exam->pass_percent ?? 0),
            ),
        };
    }

    /** R5.3 default — first lesson of the unit is blocked until the previous unit's exam is answered. */
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
