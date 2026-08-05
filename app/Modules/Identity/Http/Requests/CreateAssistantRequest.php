<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Teacher adds an assistant to their academy (M18). Password is optional — if
 * omitted one is generated and returned once. `permissions` is the delegated
 * subset; unknown values are rejected.
 */
class CreateAssistantRequest extends FormRequest
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
            'password' => ['nullable', 'string', Password::min(8)],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => [Rule::enum(Permission::class)],
        ];
    }
}
