<?php

namespace App\Modules\Centers\Http\Requests;

use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Generate a batch of activation codes. `wallet` codes need `amount_minor`;
 * `course` codes need a `course_id` owned by this tenant.
 */
class GenerateCodesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role:teacher
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            'type' => ['required', Rule::in(['wallet', 'course'])],
            'count' => ['required', 'integer', 'min:1', 'max:1000'],
            'amount_minor' => ['required_if:type,wallet', 'nullable', 'integer', 'min:1'],
            'course_id' => ['required_if:type,course', 'nullable', Rule::exists('courses', 'id')->where('tenant_id', $tenantId)],
            'center_id' => ['nullable', Rule::exists('centers', 'id')->where('tenant_id', $tenantId)],
            'batch' => ['nullable', 'string', 'max:100'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
