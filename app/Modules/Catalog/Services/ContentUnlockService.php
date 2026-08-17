<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Assessment\Enums\ExamType;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Assessment\Models\ExamAttempt;
use App\Modules\Catalog\Enums\GateRule;
use App\Modules\Catalog\Enums\LessonSectionType;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\LessonSection;
use App\Modules\Catalog\Models\PartPassOverride;

/**
 * Within-lesson content gating. Two gates coexist during the VD migration:
 *
 * 1. Per-part sequential gate (VD change set §7, LP-13/LP-14) — the new authoring
 *    model. Parts are ordered by sort_order; a quiz/homework part carries a
 *    `gate_rule` that gates every LATER part until the student clears it:
 *      must_submit — the backing exam has a submitted attempt.
 *      must_pass   — a submitted attempt's best score meets the exam's degree of
 *                    success (pass_mode/pass_value, best across tries — LP-14),
 *                    OR a teacher pass-override row exists (part_pass_overrides,
 *                    LP-D3). Once one gate is unmet, all following parts lock.
 *
 * 2. Legacy solution gate (doc 11) — answer videos hidden until the matching
 *    lesson-level exam is submitted:
 *      quiz_solution — locked until this lesson's lesson_quiz has a submitted attempt.
 *      hw_solution   — locked until this lesson's homework  has a submitted attempt.
 *
 * Plain content (video/lecture_video/pdf) is open unless a preceding gate locks it,
 * and exams themselves are never locked (attempt endpoints stay reachable — the
 * gate sequences CONTENT, not exam access). A lesson with no gating part / no
 * matching exam gates nothing. A staff-granted override on the section/lesson/unit
 * unlocks that content outright (it does NOT satisfy a must_pass gate for later
 * parts — only a real pass or a pass-override does).
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
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $overrideSets = $this->overrides->activeTargetSets($tenantId, $userId);

        // Each solution kind depends on ONE lesson-level exam — resolve once.
        $quizSubmitted = $this->examSubmitted($tenantId, $userId, (int) $lesson->getKey(), ExamType::LessonQuiz);
        $hwSubmitted = $this->examSubmitted($tenantId, $userId, (int) $lesson->getKey(), ExamType::Homework);

        $map = [];
        $gateBlocked = false; // flipped once a preceding part's gate_rule is unmet (LP-13)

        foreach ($sections as $section) {
            $covered = $this->overrides->sectionCovered($overrideSets, $section);

            // Displayed lock: an override opens the part outright; otherwise a
            // preceding unmet gate (new model) OR the legacy solution gate locks it.
            $map[(int) $section->id] = $covered
                ? false
                : ($gateBlocked || $this->lockedByType($section->type, $quizSubmitted, $hwSubmitted));

            // Does THIS part gate the parts after it? Evaluated from real progress
            // (not the override) so a content override never masks a missing pass.
            if (! $gateBlocked && $this->isGatingPart($section)
                && ! $this->gateSatisfied($tenantId, $userId, $section)) {
                $gateBlocked = true;
            }
        }

        return $map;
    }

    /**
     * Is this single section locked for the user? Delegates to lockMap so the
     * per-part sequential gate and the legacy solution gate resolve identically
     * whether we ask for one section or the whole lesson.
     */
    public function isSectionLocked(int $tenantId, int $userId, LessonSection $section): bool
    {
        $lesson = Lesson::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('id', $section->lesson_id)
            ->first();

        if ($lesson === null) {
            return false;
        }

        return $this->lockMap($tenantId, $userId, $lesson)[(int) $section->id] ?? false;
    }

    /**
     * Is `$assetId`, as delivered inside lesson `$lessonId`, blocked by a content
     * gate (a preceding part's gate_rule or the legacy solution gate)? Reachable
     * while any hosting section is unlocked; not gated when no section hosts the
     * asset (a plain lesson video). Guards the playback endpoint so the gate can't
     * be skipped by requesting a token directly.
     */
    public function isAssetLockedInLesson(int $tenantId, int $userId, int $lessonId, int $assetId): bool
    {
        $lesson = Lesson::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('id', $lessonId)
            ->first();

        if ($lesson === null) {
            return false;
        }

        $hostingIds = LessonSection::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('lesson_id', $lessonId)
            ->where('media_asset_id', $assetId)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($hostingIds === []) {
            return false;
        }

        $map = $this->lockMap($tenantId, $userId, $lesson);

        foreach ($hostingIds as $id) {
            if (($map[$id] ?? false) === false) {
                return false; // a hosting section is open → the asset is reachable
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

    /** A quiz/homework part with a gate_rule + backing exam gates the later parts. */
    private function isGatingPart(LessonSection $section): bool
    {
        return $section->gate_rule !== null && $section->exam_id !== null;
    }

    /**
     * Has the user cleared this gating part's gate_rule (VD LP-13/LP-14)?
     *   must_submit — a submitted attempt on the backing exam exists.
     *   must_pass   — a teacher pass-override exists (LP-D3), OR the best submitted
     *                 attempt meets the exam's degree of success (Exam::passed).
     * A missing backing exam gates nothing (true).
     */
    private function gateSatisfied(int $tenantId, int $userId, LessonSection $section): bool
    {
        $exam = Exam::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('id', $section->exam_id)
            ->first();

        if ($exam === null) {
            return true;
        }

        if ($section->gate_rule === GateRule::MustPass
            && PartPassOverride::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('lesson_section_id', $section->getKey())
                ->where('user_id', $userId)
                ->exists()) {
            return true;
        }

        $attempts = ExamAttempt::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('exam_id', $exam->getKey())
            ->where('user_id', $userId)
            ->whereNotNull('submitted_at')
            ->get(['score', 'max_score']);

        if ($section->gate_rule === GateRule::MustSubmit) {
            return $attempts->isNotEmpty();
        }

        // must_pass: best attempt across tries meets the degree of success (LP-14).
        foreach ($attempts as $attempt) {
            if ($exam->passed((float) $attempt->score, (float) $attempt->max_score) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Per-part result for the student (VD F14 / LP-14) — pass/fail, retake count,
     * and degree of success. Returns null for a part with no backing exam (nothing
     * to report). `degree_of_success` is the best attempt's percentage (0–100).
     * `passed` folds in a teacher pass-override (LP-D3). For a `must_submit` part,
     * "passed" means submitted; for `must_pass` (or any exam part), it means the
     * best score met the exam's degree of success.
     *
     * @return array{
     *   passed: bool, submitted: bool, attempts_used: int, max_tries: ?int,
     *   best_score: ?float, best_max: ?float, degree_of_success: ?int, via_override: bool
     * }|null
     */
    public function partResult(int $tenantId, int $userId, LessonSection $section): ?array
    {
        if ($section->exam_id === null) {
            return null;
        }

        $exam = Exam::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('id', $section->exam_id)
            ->first();

        if ($exam === null) {
            return null;
        }

        $override = PartPassOverride::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('lesson_section_id', $section->getKey())
            ->where('user_id', $userId)
            ->exists();

        $attempts = ExamAttempt::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('exam_id', $exam->getKey())
            ->where('user_id', $userId)
            ->whereNotNull('submitted_at')
            ->get(['score', 'max_score']);

        // Best attempt = highest percentage across tries (the degree of success).
        $best = null;
        $bestPct = -1.0;
        foreach ($attempts as $attempt) {
            $max = (float) $attempt->max_score;
            $pct = $max > 0 ? ((float) $attempt->score / $max) * 100 : 0.0;
            if ($pct > $bestPct) {
                $bestPct = $pct;
                $best = $attempt;
            }
        }

        $passedByScore = false;
        foreach ($attempts as $attempt) {
            if ($exam->passed((float) $attempt->score, (float) $attempt->max_score) === true) {
                $passedByScore = true;
                break;
            }
        }

        $mustSubmitOnly = $section->gate_rule === GateRule::MustSubmit;

        return [
            'passed' => $override || ($mustSubmitOnly ? $attempts->isNotEmpty() : $passedByScore),
            'submitted' => $attempts->isNotEmpty(),
            'attempts_used' => $attempts->count(),
            'max_tries' => $section->max_tries,
            'best_score' => $best !== null ? (float) $best->score : null,
            'best_max' => $best !== null ? (float) $best->max_score : null,
            'degree_of_success' => $best !== null ? (int) round($bestPct) : null,
            'via_override' => $override,
        ];
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
