<?php

namespace App\Modules\Commerce\Http\Requests;

use App\Modules\Commerce\Enums\CouponType;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create/update a coupon (M21). `code` is unique per tenant; `course` (a course
 * uuid) optionally scopes the discount to one course. All fields are required on
 * create and optional on update.
 */
class CouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role:teacher
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $couponId = $this->route('coupon')?->getKey();
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'code' => [$required, 'string', 'max:64', Rule::unique('coupons', 'code')->where('tenant_id', $tenantId)->ignore($couponId)],
            'type' => [$required, Rule::enum(CouponType::class)],
            'value' => [$required, 'integer', 'min:1'],
            'course' => ['sometimes', 'nullable', 'string', Rule::exists('courses', 'uuid')->where('tenant_id', $tenantId)],
            'min_subtotal_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'usage_limit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($this->input('type') === CouponType::Percent->value && (int) $this->input('value') > 100) {
                $v->errors()->add('value', 'A percentage coupon cannot exceed 100.');
            }
        });
    }
}
