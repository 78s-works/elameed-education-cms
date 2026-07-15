<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Models\StudentProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Teacher's full control over a student: edit identity (name/phone/email) and/or
 * change their MEMBERSHIP status (activate / suspend) in this academy. All fields
 * are optional so the teacher can patch just what they need.
 *
 * Phone and email stay unique across the WHOLE system; the student's own record
 * is ignored so re-saving unchanged values (or fixing just the case) is allowed.
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
            'phone' => ['sometimes', 'string', 'max:20', 'regex:/^[0-9+]{6,20}$/', Rule::unique('users', 'phone')->ignore($studentId)],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($studentId)],
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
            ...StudentProfile::rules(), // gender, governorate, region, academic_year, education_type, guardian_phone
        ];
    }

    /** Normalise before the unique check (mirror of CreateStudentRequest). */
    protected function prepareForValidation(): void
    {
        $merge = [];

        if (is_string($this->phone)) {
            $merge['phone'] = trim($this->phone);
        }
        if (is_string($this->email)) {
            $merge['email'] = mb_strtolower(trim($this->email));
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public function messages(): array
    {
        return [
            'phone.unique' => __('This phone number is already registered in the system.'),
            'email.unique' => __('This email address is already registered in the system.'),
        ];
    }
}
