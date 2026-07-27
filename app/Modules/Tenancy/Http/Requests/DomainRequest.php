<?php

namespace App\Modules\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Register a custom domain (M02). Semantic rules (reserved/apex/duplicate) live
 * in CustomDomainService so they can be reused; this validates the shape only.
 */
class DomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role:teacher
    }

    public function rules(): array
    {
        return [
            'host' => ['required', 'string', 'max:253'],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }
}
