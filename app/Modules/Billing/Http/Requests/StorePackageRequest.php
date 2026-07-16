<?php

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Billing\Enums\BillingInterval;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StorePackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // central + auth:sanctum + admin gates
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/', Rule::unique('subscription_packages', 'slug')],
            'description' => ['nullable', 'string', 'max:2000'],
            'price_minor' => ['required', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'interval' => ['nullable', new Enum(BillingInterval::class)],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],

            // Limits (FR-M03-02): omit a key or send null = unlimited.
            'limits' => ['nullable', 'array'],
            'limits.max_students' => ['nullable', 'integer', 'min:0'],
            'limits.max_courses' => ['nullable', 'integer', 'min:0'],
            'limits.storage_mb' => ['nullable', 'integer', 'min:0'],
            'limits.max_assistants' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
