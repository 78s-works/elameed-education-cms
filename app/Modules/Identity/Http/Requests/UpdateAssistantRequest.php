<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Enums\MembershipStatus;
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
            // Roles, by uuid (M20). An assistant's authority is the union of the
            // roles they hold — the panel no longer grants loose permissions, so
            // every grant is auditable as "which role", not "which 40 checkboxes".
            'role_uuids' => ['sometimes', 'array'],
            'role_uuids.*' => ['string', 'uuid'],
            // Re-scope the assistant's years (UUIDs). Must be non-empty when sent.
            'academic_year_ids' => ['sometimes', 'array', 'min:1'],
            'academic_year_ids.*' => ['string', 'uuid'],
        ];
    }
}
