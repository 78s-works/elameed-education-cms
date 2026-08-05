<?php

namespace App\Modules\Assessment\Http\Controllers\Teacher;

use App\Modules\Assessment\Enums\QuestionType;
use App\Modules\Assessment\Http\Requests\BubbleSheetRequest;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Assessment\Models\Question;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * On-site bubble-sheet MCQ builder (doc 13 Phase 7). Reuses the `questions` table
 * — each sheet question is an `mcq` Question whose `options` hold the choice
 * labels, `correct` holds the single correct index, and `points` holds the marks.
 * The whole sheet is read/written at once (bulk upsert); `exams.total_marks` mirrors
 * Σ marks.
 *
 * The teacher-facing sheet exposes the answer key (`correct_index`); it is NEVER
 * surfaced to students — an attempt only ever sees PublicQuestionResource (no key).
 */
class BubbleSheetController
{
    /** Read the whole sheet, answer key included (teacher-only surface). */
    public function show(Exam $exam): JsonResponse
    {
        $questions = $exam->questions()->orderBy('sort_order')->orderBy('id')->get();

        return response()->json(['data' => $this->present($exam, $questions)]);
    }

    /**
     * Replace the exam's sheet with the submitted questions in one shot. Existing
     * questions are dropped and rebuilt in payload order so the sheet is always a
     * faithful mirror of the request. `total_marks` is taken from the body when
     * given (already validated to equal Σ marks) or derived from the sum.
     */
    public function update(BubbleSheetRequest $request, Exam $exam): JsonResponse
    {
        $questions = $request->validated('questions');
        $sum = array_sum(array_map(static fn ($q) => (int) $q['marks'], $questions));
        $total = $request->validated('total_marks') ?? $sum;

        $saved = DB::transaction(function () use ($exam, $questions, $total) {
            // Rebuild the sheet from scratch (bulk upsert of the whole thing).
            $exam->questions()->delete();

            foreach ($questions as $i => $q) {
                $exam->questions()->create([
                    'type' => QuestionType::Mcq->value,
                    'body' => $q['text'] ?? null,
                    'options' => array_values($q['options']),
                    'correct' => [(int) $q['correct_index']],
                    'points' => (int) $q['marks'],
                    'sort_order' => $i,
                ]);
            }

            $exam->update(['total_marks' => $total]);

            return $exam->questions()->orderBy('sort_order')->orderBy('id')->get();
        });

        return response()->json(['data' => $this->present($exam->refresh(), $saved)]);
    }

    /**
     * Shape the teacher sheet. `correct_index` is the first (only) entry of the
     * reused `correct` key.
     *
     * @param  \Illuminate\Support\Collection<int, Question>  $questions
     * @return array<string, mixed>
     */
    private function present(Exam $exam, $questions): array
    {
        return [
            'exam_uuid' => $exam->uuid,
            'grading_mode' => $exam->grading_mode?->value,
            'pass_mode' => $exam->pass_mode?->value,
            'pass_value' => $exam->pass_value,
            'total_marks' => $exam->total_marks !== null ? (int) $exam->total_marks : null,
            'questions' => $questions->map(fn (Question $q) => [
                'id' => $q->id,
                'text' => $q->body,
                'options' => $q->options ?? [],
                'correct_index' => is_array($q->correct) ? ($q->correct[0] ?? null) : null,
                'marks' => $q->points,
                'sort_order' => $q->sort_order,
            ])->values()->all(),
        ];
    }
}
