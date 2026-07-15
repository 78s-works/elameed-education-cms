<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Models\StudentProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Teacher's full control over a student: edit identity (name/phone/email) and/or
 * change their MEMBERSHIP status (activate / suspend) in this academy. All fields
 * are optional so the teacher can patch just what they need.
 */
class UpdateStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $studentId = $this->route('student')?->getKey();

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'string', 'max:30', Rule::unique('users', 'phone')->ignore($studentId)],
            'email' => ['sometimes', 'nullable', 'email', 'max:190', Rule::unique('users', 'email')->ignore($studentId)],
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
            ...StudentProfile::rules(), // gender, governorate, region, academic_year, education_type, guardian_phone
        ];
    }
}
