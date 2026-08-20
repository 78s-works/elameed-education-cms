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
     * @param  array<int|string, mixed>  $existing   prior attempt answers (holds files uploaded before submit)
     * @return array{answers: array, score: int, max_score: int, needs_manual: bool}
     */
    public function gradeSubmission(Exam $exam, array $submitted, array $existing = []): array
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
                $entry = ['answer' => $given, 'awarded' => null, 'is_correct' => null];

                // Retain a file the student uploaded (via the file endpoint) before
                // submitting — the document itself is attached to the attempt, so
                // only its uuid travels in the answer.
                $documentUuid = $existing[$q->id]['document_uuid'] ?? null;
                if ($documentUuid !== null) {
                    $entry['document_uuid'] = $documentUuid;
                    if ($given === null || $given === '') {
                        $entry['answer'] = $existing[$q->id]['answer'] ?? null;
                    }
                }

                $answers[$q->id] = $entry;
            }
        }

        return ['answers' => $answers, 'score' => $score, 'max_score' => $maxScore, 'needs_manual' => $needsManual];
    }

    /**
     * Apply teacher-assigned points to the pending (manual) answers, then
     * recompute the total and finalise the attempt if nothing is left pending.
     * Optional written feedback is stored alongside and surfaced to the student
     * once graded. The corrected/annotated file is a document attached to the
     * attempt, written by the caller — it is not part of this payload.
     *
     * @param  array<int|string, int>  $grades  question_id => points
     */
    public function applyManualGrades(ExamAttempt $attempt, array $grades, ?string $feedback = null): ExamAttempt
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

        $payload = [
            'answers' => $answers,
            'score' => $score,
            'needs_manual_grade' => $stillPending,
            'status' => $stillPending ? 'submitted' : 'graded',
        ];

        // Only overwrite feedback when the caller supplied one, so a re-grade
        // without it keeps what was written before.
        if ($feedback !== null) {
            $payload['feedback'] = $feedback;
        }

        $attempt->update($payload);

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
