<?php

namespace App\Modules\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorized by the role:teacher middleware
    }

    public function rules(): array
    {
        // Either toggle may be sent on its own (partial update).
        return [
            'login_enabled' => ['sometimes', 'boolean'],
            'registration_enabled' => ['sometimes', 'boolean'],
            'registration_verification_mode' => ['sometimes', 'string', Rule::in(['auto', 'otp'])],
        ];
    }
}
