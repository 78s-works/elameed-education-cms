<?php

namespace App\Modules\Assessment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GradeAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // { "<question_id>": <points> }
            'grades' => ['required', 'array', 'min:1'],
            'grades.*' => ['integer', 'min:0'],
        ];
    }
}
