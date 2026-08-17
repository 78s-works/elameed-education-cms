<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A teacher/assistant granting a student access (doc 11 R7) via the
 * `target_type` + `target` pair: lesson → numeric id; package|exam → a uuid.
 * (`courses`/units retired — VD §7.)
 */
class EnrollStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target_type' => ['required', Rule::in(['lesson', 'package', 'exam'])],
            'target' => ['required', 'string'],
        ];
    }
}
