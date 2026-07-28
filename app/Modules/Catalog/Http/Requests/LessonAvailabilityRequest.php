<?php

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Teacher config for a lesson's time-boxed access. `availability_days` = null
 * means unlimited (no window). `max_extensions` / `extension_hours` govern the
 * post-expiry extension flow.
 */
class LessonAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'availability_days' => ['present', 'nullable', 'integer', 'min:1', 'max:3650'],
            'max_extensions' => ['nullable', 'integer', 'min:0', 'max:100'],
            'extension_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
        ];
    }
}
