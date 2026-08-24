<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Enums\Permission as PermissionEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edits one of the academy's own roles (M20). Every field is optional — the
 * panel patches a name without resending the permission set. System roles are
 * refused before this runs; see RoleController.
 */
class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // can:team.roles.manage on the route
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(PermissionEnum::values())],
        ];
    }
}
