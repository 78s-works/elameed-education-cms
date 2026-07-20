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
            'subtitle' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            // Marketing copy — plain string lists the frontend renders as bullets.
            'learning_outcomes' => ['nullable', 'array', 'max:30'],
            'learning_outcomes.*' => ['string', 'max:300'],
            'requirements' => ['nullable', 'array', 'max:30'],
            'requirements.*' => ['string', 'max:300'],
            'audience' => ['nullable', 'array', 'max:30'],
            'audience.*' => ['string', 'max:300'],
            // Teacher-authored curriculum outline (separate from the real units→lessons tree).
            'parts' => ['nullable', 'array', 'max:50'],
            'parts.*.title' => ['required', 'string', 'max:255'],
            'parts.*.lessons_count' => ['nullable', 'integer', 'min:0'],
            'parts.*.duration_min' => ['nullable', 'integer', 'min:0'],
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
            'cover_url' => ['nullable', 'url', 'max:2048'],        // wide hero banner
            'thumbnail_url' => ['nullable', 'url', 'max:2048'],    // small card/grid preview
            'promo_video_url' => ['nullable', 'url', 'max:2048'], // public teaser (YouTube/hosted)
            'points' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
