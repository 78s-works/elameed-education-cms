<?php

namespace App\Modules\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // The plan to assign, by uuid. Retired (soft-deleted) plans can't be assigned.
            'package_uuid' => ['required', 'string', Rule::exists('subscription_packages', 'uuid')->whereNull('deleted_at')],

            // Optional overrides for a new-teacher discount / free trial (FR-M03-04).
            'price_minor' => ['nullable', 'integer', 'min:0'],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'discount_reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
