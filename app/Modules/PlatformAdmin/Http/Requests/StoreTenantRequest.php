<?php

namespace App\Modules\PlatformAdmin\Http\Requests;

use App\Modules\Tenancy\Enums\TenantStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;

class StoreTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // admin middleware
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/',
                // Reserved slugs (e.g. "admin") would collide with a central host.
                Rule::notIn((array) config('tenancy.reserved_slugs', [])),
                Rule::unique('tenants', 'slug'),
            ],
            'status' => ['nullable', new Enum(TenantStatus::class)],

            // Optional owner (teacher) to provision alongside the tenant.
            'owner' => ['nullable', 'array'],
            'owner.name' => ['required_with:owner', 'string', 'max:255'],
            'owner.phone' => ['required_with:owner', 'string', 'max:20'],
            'owner.email' => ['nullable', 'email', 'max:255'],
            'owner.password' => ['required_with:owner', 'string', Password::min(8)],
        ];
    }
}
