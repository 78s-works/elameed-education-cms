<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Validation for creating/updating a package (bundle). Items are the courses,
 * units, and lessons the package unlocks; each is verified to belong to THIS
 * tenant. On create (`POST`) at least one item is required; on update, `items` is
 * optional and only re-synced when supplied.
 */
class BundleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role:teacher middleware
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $itemsRule = $this->isMethod('post') ? ['required', 'array', 'min:1', 'max:100'] : ['sometimes', 'array', 'max:100'];

        return [
            'title' => ['required', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price_minor' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'access_days' => ['nullable', 'integer', 'min:1'],
            'visibility' => ['nullable', new Enum(ContentVisibility::class)],
            'publish_at' => ['nullable', 'date'],
            'is_free' => ['boolean'],
            'purchase_enabled' => ['boolean'],
            'cover_url' => ['nullable', 'url', 'max:2048'],
            'thumbnail_url' => ['nullable', 'url', 'max:2048'],

            'items' => $itemsRule,
            'items.*.type' => ['required', Rule::in(['course', 'unit', 'lesson'])],
            'items.*.course' => [
                'required_if:items.*.type,course',
                Rule::exists('courses', 'uuid')->where('tenant_id', $tenantId),
            ],
            'items.*.unit' => [
                'required_if:items.*.type,unit',
                Rule::exists('units', 'id')->where('tenant_id', $tenantId),
            ],
            'items.*.lesson' => [
                'required_if:items.*.type,lesson',
                Rule::exists('lessons', 'id')->where('tenant_id', $tenantId),
            ],
            'items.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
