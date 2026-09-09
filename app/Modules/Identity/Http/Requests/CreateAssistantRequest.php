<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
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
            // Roles, by uuid (M20). An assistant's authority is the union of the
            // roles they hold — the panel no longer grants loose permissions, so
            // every grant is auditable as "which role", not "which 40 checkboxes".
            'role_uuids' => ['sometimes', 'array'],
            'role_uuids.*' => ['string', 'uuid'],
            // Year assignment (UUIDs). Optional — defaults to the active year.
            'academic_year_ids' => ['sometimes', 'array'],
            'academic_year_ids.*' => ['string', 'uuid'],
        ];
    }
}
