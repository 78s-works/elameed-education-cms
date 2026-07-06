<?php

namespace App\Modules\Assessment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // { "<question_id>": <answer> } — answer shape varies by type.
            'answers' => ['present', 'array'],
        ];
    }
}
