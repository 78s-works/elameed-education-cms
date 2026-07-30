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
            // Optional written feedback + an annotated/corrected file (upload homework).
            'feedback' => ['nullable', 'string', 'max:5000'],
            'corrected_file' => [
                'nullable',
                'file',
                'max:'.(int) config('assessment.upload_max_kb', 20480),
                'mimes:'.config('assessment.upload_mimes', 'pdf,doc,docx,ppt,pptx,xls,xlsx,txt,png,jpg,jpeg,zip'),
            ],
        ];
    }
}
