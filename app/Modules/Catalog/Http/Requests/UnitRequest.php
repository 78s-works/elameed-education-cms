<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Enums\ContentVisibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'visibility' => ['nullable', new Enum(ContentVisibility::class)],
            'publish_at' => ['nullable', 'date'],
        ];
    }
}
