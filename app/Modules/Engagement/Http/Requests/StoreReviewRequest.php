<?php

namespace App\Modules\Engagement\Http\Requests;

use App\Modules\Engagement\Models\Review;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Student review of a content target (lesson|package). Access to the target is
 * checked against enrollment in the controller (VD §7 — `courses` retired).
 */
class StoreReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is checked against enrollment in the controller
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            'target_type' => ['required', Rule::in(Review::targetTypes())],
            'target_id' => [
                'required', 'integer',
                Rule::exists(
                    $this->input('target_type') === Review::TARGET_PACKAGE ? 'packages' : 'lessons',
                    'id',
                )->where('tenant_id', $tenantId),
            ],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
