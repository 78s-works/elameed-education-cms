<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Models\StudentProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],   // الاسم رباعي
            'phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+]{6,20}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            // Client sends `password_confirmation` (تأكيد كلمة المرور); must match.
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
            'locale' => ['sometimes', 'string', 'in:ar,en'],
            ...StudentProfile::rules(), // gender, governorate, region, academic_year, education_type, guardian_phone
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->phone)) {
            $this->merge(['phone' => trim($this->phone)]);
        }
    }
}
