<?php

namespace App\Modules\Assessment\Http\Requests;

use App\Support\Files\DocumentRules;
use App\Support\Files\Enums\DocumentPurpose;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Student uploads a file answer for a `file`-type question on an in-progress
 * attempt. The file becomes a document attached to the attempt, on a private
 * disk; the student never sees or supplies a storage path. Size and type limits
 * come from the purpose's config block, not from a rule written out here.
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
            'file' => DocumentRules::for(DocumentPurpose::AssignmentSubmission),
        ];
    }
}
