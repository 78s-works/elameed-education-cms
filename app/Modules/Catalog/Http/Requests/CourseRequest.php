<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class CourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role:teacher middleware
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            // Category must belong to THIS tenant.
            'category_id' => ['nullable', Rule::exists('course_categories', 'id')->where('tenant_id', $tenantId)],
            'price_minor' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'access_days' => ['nullable', 'integer', 'min:1'],
            'visibility' => ['nullable', new Enum(ContentVisibility::class)],
            'publish_at' => ['nullable', 'date'],
            'is_free' => ['boolean'],
            'purchase_enabled' => ['boolean'],
            'is_center' => ['boolean'],
            'cover_url' => ['nullable', 'url', 'max:2048'],
            'points' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
