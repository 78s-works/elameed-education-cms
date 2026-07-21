<?php

namespace App\Modules\Identity\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class LinkParentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role:teacher
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+]{6,20}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'relation' => ['nullable', Rule::in(['father', 'mother', 'guardian'])],
            // The teacher sets the parent's password — no random one is generated.
            // Required only when a new parent account is created; if the phone already
            // belongs to a user, we just link that existing account (password ignored).
            'password' => [
                Rule::requiredIf(fn (): bool => ! User::query()->where('phone', $this->input('phone'))->exists()),
                'string',
                Password::min(8),
            ],
        ];
    }
}
