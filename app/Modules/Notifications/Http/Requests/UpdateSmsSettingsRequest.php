<?php

namespace App\Modules\Notifications\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Teacher edits his own tenant's WE Business SMS credentials. `password` is
 * write-only: send it to set/replace, omit it to keep the stored one. When
 * `enabled` is true the credential set must be complete — enforced in the
 * controller after merging with what is already stored (so a teacher can flip
 * the switch without re-entering everything).
 */
class UpdateSmsSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorized by the role:teacher middleware
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'sender' => ['nullable', 'string', 'max:20'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'account_id' => ['nullable', 'string', 'max:64'],
            'base_url' => ['nullable', 'url', 'max:255'],
        ];
    }
}
