<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Models\StudentProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Teacher manually adds a student to their academy, with the full registration
 * details. Password is optional — if omitted, one is generated and returned once.
 */
class CreateStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role:teacher
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+]{6,20}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'password' => ['nullable', 'string', Password::min(8)],
            ...StudentProfile::rules(), // gender, governorate, region, academic_year, education_type, guardian_phone, study_mode
            // A center student is onboarded by an UNUSED Center ID-code (B21 parity):
            // the code binds center + grade + study_mode, so it is required when the
            // teacher marks the student as `center`. The controller consumes it and
            // is the single source of truth (it overrides center/year from the code).
            'id_code' => ['nullable', 'string', 'max:40', 'required_if:study_mode,center'],
        ];
    }
}
