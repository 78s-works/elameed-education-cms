<?php

namespace App\Modules\PlatformAdmin\Http\Requests;

use App\Modules\Tenancy\Enums\TenantStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', new Enum(TenantStatus::class)],
        ];
    }
}
