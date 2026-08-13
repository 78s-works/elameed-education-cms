<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Enums\AccessMode;
use App\Modules\Catalog\Models\PackageType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Package authoring (VD change set §8.4). The academic year comes from the
 * X-Academic-Year context, never the body (LP-10). On update, `name` /
 * `access_mode` are optional; narrowing `access_mode` is re-checked against the
 * package's existing children in the controller via PackageItemService.
 */
class PackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role:teacher middleware
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'access_mode' => [$required, Rule::enum(AccessMode::class)],
            'price_minor' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'is_purchasable' => ['boolean'],
            // Optional type link (B27). Validated through the PackageType model so
            // its tenant + academic-year global scopes constrain it: a type from
            // another tenant OR another year simply won't be found → rejected. The
            // active year (= the package's year) is the ceiling.
            'package_type_id' => [
                'nullable',
                'integer',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value !== null && ! PackageType::whereKey($value)->exists()) {
                        $fail('The selected package type is invalid for this academic year.');
                    }
                },
            ],
        ];
    }
}
