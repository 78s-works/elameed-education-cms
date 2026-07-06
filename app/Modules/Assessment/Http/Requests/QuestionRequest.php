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

            // Correct answer key (hidden from students).
            'correct' => ['array', 'required_if:type,mcq', 'required_if:type,true_false'],
            'correct.*' => ['nullable'],

            // Bubble-sheet reference (printed book).
            'book_ref' => ['nullable', 'array'],
            'book_ref.book' => ['nullable', 'string', 'max:255'],
            'book_ref.page' => ['nullable', 'integer', 'min:1'],
            'book_ref.qno' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
