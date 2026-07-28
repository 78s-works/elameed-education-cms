<?php

namespace App\Modules\Notifications\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Author template copy for one language (doc 10 §9). Shared by central admin and
 * the teacher dashboard; `title`/`body` may contain {var} / {{ var }} placeholders.
 */
class UpsertTranslationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'language' => ['required', 'string', 'max:8', 'regex:/^[a-z]{2}(-[A-Za-z]{2,4})?$/'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
        ];
    }
}
