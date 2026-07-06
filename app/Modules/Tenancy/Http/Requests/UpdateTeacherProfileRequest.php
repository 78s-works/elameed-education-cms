<?php

namespace App\Modules\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTeacherProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorized by the role:teacher middleware
    }

    public function rules(): array
    {
        $hex = 'regex:/^#([0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/';

        return [
            'logo_url' => ['nullable', 'url', 'max:2048'],
            'cover_url' => ['nullable', 'url', 'max:2048'],
            'primary_color' => ['nullable', 'string', $hex],
            'secondary_color' => ['nullable', 'string', $hex],
            'bio' => ['nullable', 'string', 'max:2000'],

            'contact' => ['nullable', 'array'],
            'contact.phone' => ['nullable', 'string', 'max:32'],
            'contact.email' => ['nullable', 'email', 'max:255'],
            'contact.whatsapp' => ['nullable', 'string', 'max:32'],
            'contact.address' => ['nullable', 'string', 'max:500'],

            'socials' => ['nullable', 'array'],
            'socials.*' => ['nullable', 'url', 'max:2048'],
        ];
    }
}
