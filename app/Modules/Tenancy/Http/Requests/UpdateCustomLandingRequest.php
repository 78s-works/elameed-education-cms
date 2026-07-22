<?php

namespace App\Modules\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomLandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorized by the role:teacher middleware
    }

    public function rules(): array
    {
        // Single-purpose PUT: the flag is required (an empty body is a no-op).
        return [
            'custom_landing_enabled' => ['required', 'boolean'],
        ];
    }
}
