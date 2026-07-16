<?php

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Billing\Enums\BillingInterval;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdatePackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => [
                'sometimes', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('subscription_packages', 'slug')->ignore($this->route('package')?->id),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'price_minor' => ['sometimes', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'interval' => ['sometimes', new Enum(BillingInterval::class)],
            'trial_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],

            'limits' => ['sometimes', 'nullable', 'array'],
            'limits.max_students' => ['nullable', 'integer', 'min:0'],
            'limits.max_courses' => ['nullable', 'integer', 'min:0'],
            'limits.storage_mb' => ['nullable', 'integer', 'min:0'],
            'limits.max_assistants' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
