<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Teacher edits an assistant (M18): activate/suspend and/or re-scope permissions.
 * Every field is optional (partial update); identity edits touch the shared
 * global user only for name/email/phone.
 */
class UpdateAssistantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role:teacher
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'status' => ['sometimes', Rule::in([MembershipStatus::Active->value, MembershipStatus::Suspended->value])],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => [Rule::enum(Permission::class)],
            // Re-scope the assistant's years (UUIDs). Must be non-empty when sent.
            'academic_year_ids' => ['sometimes', 'array', 'min:1'],
            'academic_year_ids.*' => ['string', 'uuid'],
        ];
    }
}
