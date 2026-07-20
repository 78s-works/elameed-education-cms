<?php

namespace App\Modules\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }
}
