<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A teacher/assistant granting a student access (doc 11 R7). Either the legacy
 * `course` (a course uuid) OR the generic `target_type` + `target` pair:
 *   course|exam → a uuid; unit|lesson → the numeric id.
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
            'course' => ['required_without:target_type', 'string'], // back-compat: course uuid
            'target_type' => ['required_with:target', Rule::in(['course', 'unit', 'lesson', 'exam'])],
            'target' => ['required_with:target_type', 'string'],
        ];
    }
}
