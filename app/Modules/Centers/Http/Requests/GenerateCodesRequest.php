<?php

namespace App\Modules\Centers\Http\Requests;

use App\Modules\Centers\Models\ActivationCode;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Generate a batch of activation codes. `wallet` codes need `amount_minor`;
 * `content` codes need a `target_type` (lesson|package) + `target_id` owned by
 * this tenant (VD §7 — `courses` retired).
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
            'type' => ['required', Rule::in(['wallet', 'content'])],
            'count' => ['required', 'integer', 'min:1', 'max:1000'],
            'amount_minor' => ['required_if:type,wallet', 'nullable', 'integer', 'min:1'],
            'target_type' => ['required_if:type,content', 'nullable', Rule::in(ActivationCode::targetTypes())],
            'target_id' => [
                'required_if:type,content', 'nullable', 'integer',
                Rule::exists(
                    $this->input('target_type') === ActivationCode::TARGET_PACKAGE ? 'packages' : 'lessons',
                    'id',
                )->where('tenant_id', $tenantId),
            ],
            'center_id' => ['nullable', Rule::exists('centers', 'id')->where('tenant_id', $tenantId)],
            'batch' => ['nullable', 'string', 'max:100'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
