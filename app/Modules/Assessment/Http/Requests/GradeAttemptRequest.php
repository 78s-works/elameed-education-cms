<?php

namespace App\Modules\Assessment\Http\Requests;

use App\Support\Files\DocumentRules;
use App\Support\Files\Enums\DocumentPurpose;
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
            // Optional written feedback + an annotated/corrected file (upload homework).
            'feedback' => ['nullable', 'string', 'max:5000'],
            'corrected_file' => DocumentRules::for(DocumentPurpose::AssignmentCorrected, required: false),
        ];
    }
}
