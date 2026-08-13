<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Models\PackageType;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Package-type authoring (B27). The tenant + academic year come from context
 * (X-Tenant / X-Academic-Year), never the body. `name` is unique within the
 * tenant + year; the check runs through the PackageType model so its
 * BelongsToTenant + BelongsToAcademicYear global scopes constrain it to the
 * current tenant + year (self is ignored on update).
 */
class PackageTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role:teacher middleware
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [
                $required,
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $exists = PackageType::query()
                        ->where('name', $value)
                        ->when($this->route('packageType'), fn ($q, $type) => $q->whereKeyNot($type->id))
                        ->exists();

                    if ($exists) {
                        $fail('A package type with this name already exists for this academic year.');
                    }
                },
            ],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
