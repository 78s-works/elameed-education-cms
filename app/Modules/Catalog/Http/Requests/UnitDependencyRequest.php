<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Enums\DependencyEnforcement;
use App\Modules\Catalog\Enums\DependencyTrigger;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Validates one configurable unit prerequisite: exactly one of a prerequisite
 * unit (`depends_on_unit_id`) or a prerequisite section (`depends_on_section_id`),
 * plus the trigger action and enforcement level.
 */
class UnitDependencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'depends_on_unit_id' => ['nullable', 'integer', 'min:1', 'required_without:depends_on_section_id'],
            'depends_on_section_id' => ['nullable', 'integer', 'min:1', 'required_without:depends_on_unit_id'],
            'trigger' => ['required', new Enum(DependencyTrigger::class)],
            'enforcement' => ['required', new Enum(DependencyEnforcement::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($this->filled('depends_on_unit_id') && $this->filled('depends_on_section_id')) {
                $v->errors()->add('depends_on_unit_id', __('Set a prerequisite unit OR section, not both.'));
            }
        });
    }
}
