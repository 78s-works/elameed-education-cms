<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Catalog\Enums\ContentAccessTarget;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Grant a manual content-access override for one student on one target
 * (a lesson, section, or unit). Target ownership within the tenant is asserted in
 * the controller.
 */
class GrantContentOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target_type' => ['required', Rule::in(ContentAccessTarget::values())],
            'target_id' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
