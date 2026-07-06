<?php

namespace App\Modules\Engagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'watch_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'watch_seconds' => ['nullable', 'integer', 'min:0'],
            'last_position_sec' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
