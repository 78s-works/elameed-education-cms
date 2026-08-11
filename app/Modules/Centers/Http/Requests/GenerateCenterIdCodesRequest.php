<?php

namespace App\Modules\Centers\Http\Requests;

use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Generate a batch of Center ID-codes (B20): a `center` (uuid, tenant-owned), a
 * `grade` (1|2|3) and how many. The codes come out sequential + grade-encoded;
 * batch_id and the running sequence are assigned server-side, never client input.
 */
class GenerateCenterIdCodesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role:teacher,assistant + permission:centers (route middleware)
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            'center' => ['required', 'string', Rule::exists('centers', 'uuid')->where('tenant_id', $tenantId)],
            'grade' => ['required', 'integer', Rule::in([1, 2, 3])],
            'count' => ['required', 'integer', 'min:1', 'max:1000'],
        ];
    }
}
