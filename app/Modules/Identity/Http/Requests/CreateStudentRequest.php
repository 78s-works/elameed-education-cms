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
            ...StudentProfile::rules(), // gender, governorate, region, academic_year, education_type, guardian_phone
        ];
    }
}
