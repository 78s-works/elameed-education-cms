<?php

namespace App\Modules\Tenancy\Http\Requests;

use App\Modules\Tenancy\Models\TeacherProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeacherLandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorized by the role:teacher middleware
    }

    public function rules(): array
    {
        return [
            // Array order defines display order; each entry toggles visibility.
            'landing_sections' => ['required', 'array', 'max:20'],
            'landing_sections.*.key' => ['required', 'string', Rule::in(TeacherProfile::LANDING_SECTION_KEYS)],
            'landing_sections.*.visible' => ['required', 'boolean'],
        ];
    }
}
