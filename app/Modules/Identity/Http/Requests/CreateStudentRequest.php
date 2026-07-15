<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Models\StudentProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Teacher manually adds a student to their academy, with the full registration
 * details. Password is optional — if omitted, one is generated and returned once.
 *
 * Phone and email are unique across the WHOLE system (one global identity per
 * person; see the unique columns on `users`), so a value already registered to
 * anyone is rejected here up front with a clear message rather than surfacing as
 * a raw DB error or silently reusing someone else's account.
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
            'phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+]{6,20}$/', Rule::unique('users', 'phone')],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['nullable', 'string', Password::min(8)],
            ...StudentProfile::rules(), // gender, governorate, region, academic_year, education_type, guardian_phone
        ];
    }

    /**
     * Normalise before the unique check so a trailing space or a differently-cased
     * email can't slip a duplicate past validation.
     */
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
