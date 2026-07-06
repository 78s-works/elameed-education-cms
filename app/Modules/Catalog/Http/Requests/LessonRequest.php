<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Enums\ContentVisibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class LessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'duration_sec' => ['nullable', 'integer', 'min:0'],
            'max_views' => ['nullable', 'integer', 'min:1'],
            'is_free_preview' => ['boolean'],
            'visibility' => ['nullable', new Enum(ContentVisibility::class)],
            'publish_at' => ['nullable', 'date'],
            // video_asset_id is assigned by the Media step, not here.
        ];
    }
}
