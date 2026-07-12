<?php

namespace App\Modules\Tenancy\Http\Requests;

use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\CourseCategory;
use App\Modules\Tenancy\Support\LandingSchema;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Authors the landing (LANDING_CONTRACT_V2.md): layout + ordered, typed sections.
 * Content is validated per section type; dynamic sections validate their `config`
 * and that referenced categories/courses belong to this teacher.
 */
class UpdateTeacherLandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role:teacher middleware
    }

    public function rules(): array
    {
        $rules = [
            'layout' => ['sometimes', Rule::in(LandingSchema::LAYOUTS)],
            'sections' => ['required', 'array', 'max:30'],
            'sections.*.key' => ['required', 'string', 'max:40'],
            'sections.*.type' => ['required', 'string', Rule::in(LandingSchema::TYPES)],
            'sections.*.visible' => ['required', 'boolean'],
            'sections.*.order' => ['nullable', 'integer', 'min:1'],
            'sections.*.content' => ['sometimes', 'array'],
        ];

        foreach ((array) $this->input('sections', []) as $i => $section) {
            $type = is_array($section) ? ($section['type'] ?? null) : null;
            if (! is_string($type)) {
                continue;
            }
            foreach (LandingSchema::contentRules($type) as $field => $fieldRules) {
                $rules["sections.{$i}.content.{$field}"] = $fieldRules;
            }
            foreach (LandingSchema::configRules($type) as $field => $fieldRules) {
                $rules["sections.{$i}.{$field}"] = $fieldRules;
            }
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            foreach ((array) $this->input('sections', []) as $i => $section) {
                if (($section['type'] ?? null) !== 'courses') {
                    continue;
                }
                $config = $section['config'] ?? [];

                if (($config['source'] ?? null) === 'category' && ! empty($config['category_id'])) {
                    // Category models are tenant-scoped → exists() implies ownership.
                    if (! CourseCategory::query()->whereKey($config['category_id'])->exists()) {
                        $v->errors()->add("sections.{$i}.config.category_id", __('Category not found in this academy.'));
                    }
                }

                if (($config['source'] ?? null) === 'selected') {
                    $ids = array_values(array_unique(array_map('intval', (array) ($config['course_ids'] ?? []))));
                    if ($ids !== [] && Course::query()->whereIn('id', $ids)->count() !== count($ids)) {
                        $v->errors()->add("sections.{$i}.config.course_ids", __('One or more selected courses are not yours.'));
                    }
                }
            }
        });
    }
}
