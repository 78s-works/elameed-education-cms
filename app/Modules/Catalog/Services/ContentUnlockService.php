<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Assessment\Enums\ExamType;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Assessment\Models\ExamAttempt;
use App\Modules\Catalog\Enums\LessonSectionType;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\LessonSection;

/**
 * The ONLY within-lesson gate left in the convention model: solution/answer
 * videos are hidden until the matching exam is submitted.
 *
 *   quiz_solution — locked until this lesson's lesson_quiz has a submitted attempt.
 *   hw_solution   — locked until this lesson's homework  has a submitted attempt.
 *
 * Every other section type (lecture_video, pdf) is always open, and exams are
 * never locked. A lesson with no (published) exam of the matching type doesn't
 * gate its solution — an orphan solution simply shows. A staff-granted override
 * on the section/lesson/unit unlocks outright.
 *
 * Access-critical, so tenant id is explicit and queries run withoutGlobalScopes
 * (mirrors EnrollmentService).
 */
class ContentUnlockService
{
    public function __construct(
        private readonly ContentAccessOverrideService $overrides,
    ) {}

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
            ->get();

        $overrideSets = $this->overrides->activeTargetSets($tenantId, $userId);
        $unitId = $lesson->unit_id !== null ? (int) $lesson->unit_id : null;

        // Each solution kind depends on ONE lesson-level exam — resolve once.
        $quizSubmitted = $this->examSubmitted($tenantId, $userId, (int) $lesson->getKey(), ExamType::LessonQuiz);
        $hwSubmitted = $this->examSubmitted($tenantId, $userId, (int) $lesson->getKey(), ExamType::Homework);

        $map = [];
        foreach ($sections as $section) {
            if ($this->overrides->sectionCovered($overrideSets, $section, $unitId)) {
                $map[(int) $section->id] = false;

                continue;
            }

            $map[(int) $section->id] = $this->lockedByType($section->type, $quizSubmitted, $hwSubmitted);
        }

        return $map;
    }

    /** Is this single section locked for the user? */
    public function isSectionLocked(int $tenantId, int $userId, LessonSection $section): bool
    {
        if (! $section->type->isSolution()) {
            return false; // only solution videos are gated
        }

        $unitId = Lesson::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('id', $section->lesson_id)
            ->value('unit_id');

        if ($this->overrides->hasActiveForSection($tenantId, $userId, $section, $unitId !== null ? (int) $unitId : null)) {
            return false;
        }

        $type = $section->type === LessonSectionType::QuizSolution ? ExamType::LessonQuiz : ExamType::Homework;

        return ! $this->examSubmitted($tenantId, $userId, (int) $section->lesson_id, $type);
    }

    /**
     * Is `$assetId`, as delivered inside lesson `$lessonId`, blocked by a solution
     * gate? Reachable while any hosting section is unlocked; not gated when no
     * section hosts the asset (a plain lesson video). Guards the playback endpoint
     * so the solution gate can't be skipped by requesting a token directly.
     */
    public function isAssetLockedInLesson(int $tenantId, int $userId, int $lessonId, int $assetId): bool
    {
        $sections = LessonSection::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('lesson_id', $lessonId)
            ->where('media_asset_id', $assetId)
            ->get();

        if ($sections->isEmpty()) {
            return false;
        }

        foreach ($sections as $section) {
            if (! $this->isSectionLocked($tenantId, $userId, $section)) {
                return false;
            }
        }

        return true;
    }

    /** @return bool locked? */
    private function lockedByType(LessonSectionType $type, bool $quizSubmitted, bool $hwSubmitted): bool
    {
        return match ($type) {
            LessonSectionType::QuizSolution => ! $quizSubmitted,
            LessonSectionType::HwSolution => ! $hwSubmitted,
            default => false,
        };
    }

    /**
     * Has the user submitted the lesson's published exam of $type? True when the
     * lesson has no such exam (nothing to gate → the solution shows).
     */
    private function examSubmitted(int $tenantId, int $userId, int $lessonId, ExamType $type): bool
    {
        $exam = Exam::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('lesson_id', $lessonId)
            ->where('type', $type->value)
            ->where('is_published', true)
            ->first();

        if ($exam === null) {
            return true;
        }

        return ExamAttempt::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('exam_id', $exam->getKey())
            ->where('user_id', $userId)
            ->whereNotNull('submitted_at')
            ->exists();
    }
}
