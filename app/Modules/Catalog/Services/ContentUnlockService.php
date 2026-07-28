<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Assessment\Models\Exam;
use App\Modules\Assessment\Models\ExamAttempt;
use App\Modules\Catalog\Enums\DependencyTrigger;
use App\Modules\Catalog\Enums\LessonSectionType;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\LessonSection;
use App\Modules\Engagement\Models\LessonProgress;
use Illuminate\Support\Collection;

/**
 * Evaluates "Content Dependencies & Unlock Rules": whether a student may access
 * a lesson section given the completion state of its prerequisite sections.
 *
 * Only MANDATORY dependencies gate; optional ones are advisory (surfaced by the
 * resource, never blocking). A section is locked if ANY mandatory prerequisite's
 * trigger is unmet. Access-critical, so tenant id is explicit and queries run
 * withoutGlobalScopes (mirrors EnrollmentService).
 */
class ContentUnlockService
{
    /**
     * Locked-state for every section of a lesson.
     *
     * @return array<int, bool> section_id => isLocked
     */
    public function lockMap(int $tenantId, int $userId, Lesson $lesson): array
    {
        $sections = LessonSection::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('lesson_id', $lesson->getKey())
            ->with('dependencies')
            ->get();

        $byId = $sections->keyBy('id');
        $map = [];

        foreach ($sections as $section) {
            $locked = false;

            foreach ($section->dependencies as $dep) {
                if (! $dep->isMandatory()) {
                    continue;
                }

                $prereq = $byId->get($dep->depends_on_section_id);
                if ($prereq === null) {
                    continue; // dangling prerequisite — treat as satisfied
                }

                if (! $this->triggerMet($tenantId, $userId, $prereq, $dep->trigger)) {
                    $locked = true;
                    break;
                }
            }

            $map[(int) $section->id] = $locked;
        }

        return $map;
    }

    /** Is this single section locked for the user? */
    public function isSectionLocked(int $tenantId, int $userId, LessonSection $section): bool
    {
        $section->loadMissing('dependencies');

        foreach ($section->dependencies as $dep) {
            if (! $dep->isMandatory()) {
                continue;
            }

            $prereq = LessonSection::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->find($dep->depends_on_section_id);

            if ($prereq === null) {
                continue;
            }

            if (! $this->triggerMet($tenantId, $userId, $prereq, $dep->trigger)) {
                return true;
            }
        }

        return false;
    }

    private function triggerMet(int $tenantId, int $userId, LessonSection $prereq, DependencyTrigger $trigger): bool
    {
        if ($prereq->type->usesExam() && $prereq->exam_id !== null) {
            return $this->examTriggerMet($tenantId, $userId, (int) $prereq->exam_id, $trigger);
        }

        return $this->mediaTriggerMet($tenantId, $userId, $prereq);
    }

    private function examTriggerMet(int $tenantId, int $userId, int $examId, DependencyTrigger $trigger): bool
    {
        $attempts = ExamAttempt::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('exam_id', $examId)
            ->where('user_id', $userId)
            ->whereNotNull('submitted_at')
            ->get();

        if ($attempts->isEmpty()) {
            return false;
        }

        return match ($trigger) {
            DependencyTrigger::Submitted, DependencyTrigger::Completed => true,
            DependencyTrigger::Passed => $this->anyPassed($tenantId, $examId, $attempts),
        };
    }

    /** @param Collection<int, ExamAttempt> $attempts */
    private function anyPassed(int $tenantId, int $examId, $attempts): bool
    {
        $exam = Exam::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($examId);
        $need = (int) ($exam?->pass_percent ?? 0);

        foreach ($attempts as $attempt) {
            $max = (int) ($attempt->max_score ?? 0);
            if ($max <= 0) {
                continue;
            }
            $pct = ((int) ($attempt->score ?? 0)) / $max * 100;
            if ($pct >= $need) {
                return true;
            }
        }

        return false;
    }

    private function mediaTriggerMet(int $tenantId, int $userId, LessonSection $prereq): bool
    {
        // A PDF has no completion signal — reading isn't tracked — so it can't gate.
        if ($prereq->type === LessonSectionType::Pdf) {
            return true;
        }

        // Video sections: completion is the lesson's watch-completion.
        return LessonProgress::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('lesson_id', $prereq->lesson_id)
            ->whereNotNull('completed_at')
            ->exists();
    }
}
