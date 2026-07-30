<?php

namespace App\Modules\Assessment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a question by type: mcq needs options + correct indices; true_false
 * needs a correct boolean; short/essay need a body prompt; bubble-sheet mcq may
 * omit the body but carries a book_ref.
 */
class QuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['mcq', 'true_false', 'short', 'essay', 'file'])],
            'body' => ['nullable', 'string', 'required_if:type,short,essay'],
            'points' => ['nullable', 'integer', 'min:1'],
            'sort_order' => ['nullable', 'integer', 'min:0'],

            'options' => ['array', 'required_if:type,mcq', 'min:2'],
            'options.*' => ['string', 'max:500'],

            // Correct answer key (hidden from students). Shape is constrained per
            // type so a malformed key (e.g. true_false with ["yes"]) can't be saved
            // as a question no student can ever answer correctly.
            'correct' => ['array', 'required_if:type,mcq', 'required_if:type,true_false'],
            'correct.*' => [
                'nullable',
                Rule::when($this->input('type') === 'true_false', ['boolean']),
                Rule::when($this->input('type') === 'mcq', ['integer', 'min:0']),
            ],

            // Bubble-sheet reference (printed book).
            'book_ref' => ['nullable', 'array'],
            'book_ref.book' => ['nullable', 'string', 'max:255'],
            'book_ref.page' => ['nullable', 'integer', 'min:1'],
            'book_ref.qno' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->input('type') !== 'mcq') {
                return;
            }
            $optionCount = is_array($this->input('options')) ? count($this->input('options')) : 0;
            foreach ((array) $this->input('correct', []) as $i => $index) {
                if (is_numeric($index) && (int) $index >= $optionCount) {
                    $validator->errors()->add("correct.$i", 'The correct answer index is out of range for the options.');
                }
            }
        });
    }
}
