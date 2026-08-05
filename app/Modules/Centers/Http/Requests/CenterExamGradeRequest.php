<?php

namespace App\Modules\Centers\Http\Requests;

use App\Models\User;
use App\Modules\Centers\Models\Center;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create/update a center paper-exam grade (VD R12). `center` + `student` are
 * uuids resolved against the current tenant; a foreign uuid fails validation so
 * a teacher can never grade against another academy's center or student. The
 * grade's academic year comes from the X-Academic-Year context, not the body.
 */
class CenterExamGradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by role:teacher,assistant + permission:centers
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'center' => ['required', 'string', $this->centerInTenant()],
            'student' => ['required', 'string', $this->studentInTenant()],
            'total_marks' => ['required', 'numeric', 'min:0'],
            'score' => ['required', 'numeric', 'min:0', 'lte:total_marks'],
            'sat_on' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
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

    /** The student uuid must be an active Student member of the current tenant. */
    private function studentInTenant(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $tenantId = app(TenantContext::class)->tenantOrFail()->getKey();

            $user = User::query()->where('uuid', $value)->first();

            $isStudent = $user !== null && TenantUser::query()
                ->where('tenant_id', $tenantId)
                ->where('user_id', $user->getKey())
                ->where('role', TenantUserRole::Student->value)
                ->exists();

            if (! $isStudent) {
                $fail('The selected student is invalid.');
            }
        };
    }
}
