<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Enums\Permission as PermissionEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Platform-admin edit of a role template (M20). Authorisation is the route's
 * `admin` middleware — a template is platform data, not tenant data.
 */
class UpdateRoleTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],

            // Unknown keys are rejected outright rather than silently dropped: a
            // template that quietly loses a permission the admin ticked is worse
            // than a 422 saying the key does not exist.
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(PermissionEnum::values())],
        ];
    }
}
