<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Enums\Permission as PermissionEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Teacher (or a delegate holding `team.roles.manage`) authors a role in their own
 * academy (M20). What may go INSIDE it is checked by TeamAuthority, not here:
 * validation says the keys exist, authority says the caller may hand them out.
 */
class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // can:team.roles.manage on the route
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(PermissionEnum::values())],
        ];
    }
}
