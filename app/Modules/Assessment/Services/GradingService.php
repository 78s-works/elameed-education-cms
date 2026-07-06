<?php

namespace App\Modules\Assessment\Services;

use App\Modules\Assessment\Models\Exam;
use App\Modules\Assessment\Models\ExamAttempt;

/**
 * Grades attempts. Objective questions (mcq / true_false) are graded on submit;
 * subjective ones (short / essay / file) are left for the teacher and flip the
 * attempt's `needs_manual_grade`.
 */
class GradingService
{
    /**
     * @param  array<int|string, mixed>  $submitted  question_id => answer
     * @return array{answers: array, score: int, max_score: int, needs_manual: bool}
     */
    public function gradeSubmission(Exam $exam, array $submitted): array
    {
        $answers = [];
        $score = 0;
        $maxScore = 0;
        $needsManual = false;

        foreach ($exam->questions as $q) {
            $maxScore += (int) $q->points;
            $given = $submitted[$q->id] ?? null;

            if ($q->type->isAutoGraded()) {
                $isCorrect = $this->matches($given, $q->correct ?? []);
                $awarded = $isCorrect ? (int) $q->points : 0;
                $score += $awarded;
                $answers[$q->id] = ['answer' => $given, 'awarded' => $awarded, 'is_correct' => $isCorrect];
            } else {
                $needsManual = true;
                $answers[$q->id] = ['answer' => $given, 'awarded' => null, 'is_correct' => null];
            }
        }

        return ['answers' => $answers, 'score' => $score, 'max_score' => $maxScore, 'needs_manual' => $needsManual];
    }

    /**
     * Apply teacher-assigned points to the pending (manual) answers, then
     * recompute the total and finalise the attempt if nothing is left pending.
     *
     * @param  array<int|string, int>  $grades  question_id => points
     */
    public function applyManualGrades(ExamAttempt $attempt, array $grades): ExamAttempt
    {
        $answers = $attempt->answers ?? [];
        $pointsByQuestion = $attempt->exam->questions->pluck('points', 'id');

        foreach ($grades as $qid => $points) {
            if (! isset($answers[$qid])) {
                continue;
            }
            $max = (int) ($pointsByQuestion[$qid] ?? 0);
            $answers[$qid]['awarded'] = max(0, min((int) $points, $max));
            $answers[$qid]['is_correct'] = $answers[$qid]['awarded'] === $max;
        }

        $stillPending = collect($answers)->contains(fn ($a) => $a['awarded'] === null);
        $score = collect($answers)->sum(fn ($a) => (int) ($a['awarded'] ?? 0));

        $attempt->update([
            'answers' => $answers,
            'score' => $score,
            'needs_manual_grade' => $stillPending,
            'status' => $stillPending ? 'submitted' : 'graded',
        ]);

        return $attempt;
    }

    /** Set-equality on normalised values (works for mcq indices + true/false). */
    private function matches(mixed $given, array $correct): bool
    {
        $norm = static fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : mb_strtolower(trim((string) $v));

        $a = array_map($norm, is_array($given) ? $given : [$given]);
        $b = array_map($norm, $correct);
        sort($a);
        sort($b);

        return $a === $b && $b !== [''];
    }
}
