<?php

namespace App\Modules\Assessment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Bulk-upsert of a whole bubble-sheet (doc 13 Phase 7). A sheet is a list of
 * on-site MCQ questions — each `{ text?, options[], correct_index, marks }` — with
 * an optional `total_marks` that must equal Σ marks (derived when omitted).
 *
 * Shape rules:
 *   - at least one question;
 *   - at least two options per question;
 *   - correct_index within its question's option range;
 *   - marks a positive integer (stored on the reused `questions.points` column).
 */
class BubbleSheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role:teacher
    }

    public function rules(): array
    {
        return [
            'total_marks' => ['nullable', 'integer', 'min:1'],

            'questions' => ['required', 'array', 'min:1'],
            'questions.*.text' => ['nullable', 'string', 'max:1000'],
            'questions.*.options' => ['required', 'array', 'min:2'],
            'questions.*.options.*' => ['required', 'string', 'max:500'],
            'questions.*.correct_index' => ['required', 'integer', 'min:0'],
            'questions.*.marks' => ['required', 'integer', 'min:1'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $questions = (array) $this->input('questions', []);
            $sum = 0;

            foreach ($questions as $i => $question) {
                $options = is_array($question['options'] ?? null) ? $question['options'] : [];
                $index = $question['correct_index'] ?? null;

                if (is_numeric($index) && (int) $index >= count($options)) {
                    $validator->errors()->add(
                        "questions.$i.correct_index",
                        'The correct answer index is out of range for the options.'
                    );
                }

                $sum += (int) ($question['marks'] ?? 0);
            }

            // When total_marks is supplied it must match Σ marks exactly; otherwise
            // the controller derives the total from the sum.
            $total = $this->input('total_marks');
            if ($total !== null && (int) $total !== $sum) {
                $validator->errors()->add(
                    'total_marks',
                    "total_marks must equal the sum of question marks ($sum)."
                );
            }
        });
    }
}
