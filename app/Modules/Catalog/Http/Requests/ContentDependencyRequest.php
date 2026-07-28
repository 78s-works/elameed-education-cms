<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Enums\DependencyEnforcement;
use App\Modules\Catalog\Enums\DependencyTrigger;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Validates one unlock rule for a section: which prerequisite section, the
 * trigger action, and how strictly to enforce it.
 */
class ContentDependencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'depends_on_section_id' => ['required', 'integer', 'min:1'],
            'trigger' => ['required', new Enum(DependencyTrigger::class)],
            'enforcement' => ['required', new Enum(DependencyEnforcement::class)],
        ];
    }
}
