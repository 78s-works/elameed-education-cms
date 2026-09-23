<?php

namespace App\Modules\Notifications\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Teacher edits his own academy's SMS gateway credentials.
 *
 * Two providers are accepted because the platform moved from WE Business SMS
 * (Connekio) to ZADX (EDU-OPS-004) and an academy already sending on WE must not
 * be broken by the move. `provider` selects which field set applies; omitting it
 * keeps whatever is stored, so an existing WE academy can toggle `enabled` or
 * edit its sender without being asked for keys it does not have.
 *
 * The secrets — `password` (WE) and `api_secret` (ZADX) — are write-only: send
 * one to set or replace it, omit it to keep the stored one. When `enabled` is
 * true the credential set must be complete, which the controller checks after
 * merging with what is already stored.
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
            'provider' => ['nullable', Rule::in(['zadx', 'connekio'])],
            'base_url' => ['nullable', 'url', 'max:255'],

            // ZADX. sender_id is optional: ZADX rejects one that is not assigned
            // to the app (403 sender_id_not_allowed), and omitting it uses the
            // app's own default, so a blank field is safer than a guessed value.
            'api_key' => ['nullable', 'string', 'max:255'],
            'api_secret' => ['nullable', 'string', 'max:255'],
            'sender_id' => ['nullable', 'string', 'max:20'],

            // WE Business SMS / Connekio.
            'sender' => ['nullable', 'string', 'max:20'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'account_id' => ['nullable', 'string', 'max:64'],
        ];
    }
}
