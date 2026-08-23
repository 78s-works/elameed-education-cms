<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Centers\Models\Center;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Tenancy\Models\TeacherProfile;
use Closure;
use Illuminate\Contracts\Validation\Validator;
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
        // The academy's two center-registration switches (General Settings):
        //   center_registration_enabled — is the on-site path offered at all?
        //   center_id_code_required     — when it is, must it go through a code?
        // The SPA hides/shows fields from the same flags (GET /tenant/context →
        // `auth`); these rules are the enforcement, so a crafted payload can't
        // register as a center student while the teacher has that turned off.
        $profile = TeacherProfile::query()->firstOrNew([]);
        $centerAllowed = (bool) $profile->center_registration_enabled;
        $forceIdCode = $centerAllowed && (bool) $profile->center_id_code_required;

        return [
            'name' => ['required', 'string', 'max:255'],   // الاسم رباعي
            'phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+]{6,20}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            // Client sends `password_confirmation` (تأكيد كلمة المرور); must match.
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
            'locale' => ['sometimes', 'string', 'in:ar,en'],
            // gender, governorate, region, academic_year, education_type,
            // guardian_phone (+ a permissive study_mode our own rule below narrows).
            ...StudentProfile::rules(),
            // Academic year (grade) uuid — the student's scoping container, picked
            // from the tenant's real years. Required on the manual path; on the
            // id_code path the code carries the grade instead (so it's prohibited
            // there, below). Resolved to academic_year_id in RegisterStudentAction.
            'academic_year_uuid' => ['nullable', 'required_without:id_code', 'string', $this->academicYearInTenant()],
            // How the student studies. `both` (hybrid) is retired for self sign-up
            // — a student belongs to exactly one channel. With center registration
            // switched off, `online` is the ONLY accepted value: the SPA drops the
            // field entirely and the default below fills it in.
            'study_mode' => ['nullable', 'string', $centerAllowed ? 'in:online,center' : 'in:online'],
            // Center uuid (not the numeric id) — the branch the student attends,
            // picked from the public GET /centers list. Accepted only on the
            // "pick a branch OR type a code" path: prohibited when center
            // registration is off, and prohibited when the teacher forces the
            // code (then the code is the only on-site key).
            // Resolved to student_profiles.center_id in RegisterStudentAction.
            'center' => $centerAllowed && ! $forceIdCode
                ? ['nullable', 'string', $this->centerInTenant()]
                : ['prohibited'],
            // Center ID-code (B21): the on-site path that carries its own center +
            // grade + study_mode, so manual center/study_mode/academic_year are
            // prohibited alongside it (the code wins). Required when the teacher
            // forces it, prohibited when center registration is off. The code
            // itself is validated + consumed under lock in RegisterStudentAction
            // (existence/unused there, not here, to keep one atomic redeem path).
            'id_code' => match (true) {
                ! $centerAllowed => ['prohibited'],
                $forceIdCode => ['nullable', 'required_if:study_mode,center', 'string', 'max:40', 'prohibits:center,study_mode,academic_year,academic_year_uuid'],
                default => ['nullable', 'string', 'max:40', 'prohibits:center,study_mode,academic_year,academic_year_uuid'],
            },
        ];
    }

    /** On-site students must identify their branch: pick it from the academy's
     *  center list OR type the Center ID-code that carries it — exactly one of
     *  the two (`id_code`'s `prohibits` rule rejects sending both). This lives
     *  here, not in the `center` rules, because a closure rule is skipped for an
     *  absent value — which is the very case that must fail. Only reachable on
     *  the "branch OR code" path; when the teacher forces the code, `id_code`'s
     *  own `required_if` covers it and `center` is prohibited outright. */
    public function withValidator(Validator $validator): void
    {
        $profile = TeacherProfile::query()->firstOrNew([]);
        if (! $profile->center_registration_enabled || $profile->center_id_code_required) {
            return;
        }

        $validator->after(function (Validator $validator): void {
            if ($this->input('study_mode') !== 'center') {
                return;
            }
            if (filled($this->input('center')) || filled($this->input('id_code'))) {
                return;
            }
            $validator->errors()->add('center', __('Please choose your center or enter your center ID code.'));
        });
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

    /** The academic-year uuid must resolve inside the current tenant. */
    private function academicYearInTenant(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! AcademicYear::query()->where('uuid', $value)->exists()) {
                $fail('The selected grade is invalid.');
            }
        };
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->phone)) {
            $this->merge(['phone' => trim($this->phone)]);
        }

        // Online is the default channel. With center registration switched off the
        // SPA drops the study-system field altogether, so nothing arrives — fill it
        // in rather than storing a student with no channel. An explicit `center` is
        // NOT rewritten here: the `in:online` rule above must still reject it, so a
        // crafted payload gets an error instead of a silent downgrade.
        // Never on the id_code path: a code PROHIBITS study_mode (it encodes its
        // own), so injecting a default there would fail the student's own request.
        if (blank($this->input('study_mode')) && blank($this->input('id_code'))) {
            $this->merge(['study_mode' => 'online']);
        }
    }
}
