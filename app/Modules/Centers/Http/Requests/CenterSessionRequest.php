<?php

namespace App\Modules\Centers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Create / update a center session: a name, an optional time, its center, and
 * 0+ linked lessons. The center is resolved by uuid and the lessons validated to
 * the tenant + active year in the controller.
 */
class CenterSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission:centers
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'session_at' => ['nullable', 'date'],
            'center_uuid' => ['required', 'string'],
            'lessons' => ['nullable', 'array', 'max:100'],
            'lessons.*' => ['integer'],
        ];
    }
}
