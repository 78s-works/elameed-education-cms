<?php

namespace App\Modules\Centers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CenterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role:teacher
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'is_active' => ['boolean'],
        ];
    }
}
