<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Enums\OtpPurpose;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class OtpRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:255'],
            'purpose' => ['required', new Enum(OtpPurpose::class)],
        ];
    }
}
