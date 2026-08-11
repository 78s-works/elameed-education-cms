<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Centers\Models\Center;
use App\Modules\Identity\Models\StudentProfile;
use Closure;
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
            ...StudentProfile::rules(), // gender, governorate, region, academic_year, education_type, guardian_phone, study_mode
            // Center uuid (not the numeric id) — required when the student attends
            // on-site (study_mode center|both), forbidden meaning for online-only.
            // Resolved to student_profiles.center_id in RegisterStudentAction.
            'center' => ['nullable', 'required_if:study_mode,center,both', 'string', $this->centerInTenant()],
            // Center ID-code (B21): the alternative on-site path. When present it is
            // the SINGLE source of truth for center + grade + study_mode, so manual
            // center/study_mode/academic_year are prohibited (the code wins). The
            // code itself is validated + consumed under lock in RegisterStudentAction
            // (existence/unused there, not here, to keep one atomic redeem path).
            'id_code' => ['nullable', 'string', 'max:40', 'prohibits:center,study_mode,academic_year'],
        ];
    }

    /** The center uuid must resolve inside the current tenant (BelongsToTenant scope). */
    private function centerInTenant(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! Center::query()->where('uuid', $value)->exists()) {
                $fail('The selected center is invalid.');
            }
        };
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->phone)) {
            $this->merge(['phone' => trim($this->phone)]);
        }
    }
}
