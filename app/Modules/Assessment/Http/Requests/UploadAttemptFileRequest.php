<?php

namespace App\Modules\Assessment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Student uploads a file answer for a `file`-type question on an in-progress
 * attempt. The file is stored on a PRIVATE disk under `assignments/`; the
 * student never sees or supplies the storage path.
 */
class UploadAttemptFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'question_id' => ['required', 'integer'],
            'file' => [
                'required',
                'file',
                'max:'.(int) config('assessment.upload_max_kb', 20480),
                'mimes:'.config('assessment.upload_mimes', 'pdf,doc,docx,ppt,pptx,xls,xlsx,txt,png,jpg,jpeg,zip'),
            ],
        ];
    }
}
