<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OtpVerifyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:255'],
            // Only register/login issue a token here; reset uses /password/reset.
            'purpose' => ['required', Rule::in(['register', 'login'])],
            'code' => ['required', 'string'],
        ];
    }
}
