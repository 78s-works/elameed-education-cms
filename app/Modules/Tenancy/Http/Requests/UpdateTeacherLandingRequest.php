<?php

namespace App\Modules\Tenancy\Http\Requests;

use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\CourseCategory;
use App\Modules\Tenancy\Services\TenantContext;
use App\Modules\Tenancy\Support\LandingSchema;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Authors the landing (LANDING_CONTRACT_V2, multi-language): the enabled
 * `locales` + `primary_locale`, a `layout`, and ordered, typed sections whose
 * `content` is authored PER LOCALE ({ ar: {...}, en: {...} }).
 *
 * Content is validated per section type, per enabled locale; dynamic sections
 * validate their `config` (not per-locale) and that referenced categories/courses
 * belong to this teacher. Section types are restricted to the code catalog
 * (LandingSchema::TYPES) — the teacher may add/duplicate those, not invent types.
 */
class UpdateTeacherLandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role:teacher middleware
    }

    public function rules(): array
    {
        $supported = LandingSchema::supportedLocales();
        $locales = $this->effectiveLocales();

        $rules = [
            'locales' => ['sometimes', 'array', 'min:1'],
            'locales.*' => ['string', Rule::in($supported)],
            'primary_locale' => ['sometimes', 'string', Rule::in($supported)],
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

            // Per-locale content rules (one set of the type's field rules per locale).
            foreach ($locales as $locale) {
                $rules["sections.{$i}.content.{$locale}"] = ['sometimes', 'array'];
                foreach (LandingSchema::contentRules($type) as $field => $fieldRules) {
                    $rules["sections.{$i}.content.{$locale}.{$field}"] = $fieldRules;
                }
            }

            // Config is data selection, not translated → validated once per section.
            foreach (LandingSchema::configRules($type) as $field => $fieldRules) {
                $rules["sections.{$i}.{$field}"] = $fieldRules;
            }
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // primary_locale must be one of the enabled locales (when both given).
            $locales = $this->input('locales');
            $primary = $this->input('primary_locale');
            if (is_array($locales) && is_string($primary) && ! in_array($primary, $locales, true)) {
                $v->errors()->add('primary_locale', __('The primary language must be one of the enabled languages.'));
            }

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

    /**
     * The locales whose content this request validates: the ones being set in
     * this payload, else the academy's current set, else the platform default.
     *
     * @return list<string>
     */
    private function effectiveLocales(): array
    {
        $requested = $this->input('locales');
        if (is_array($requested)) {
            return LandingSchema::normalizeLocales($requested, $this->input('primary_locale') ?? ($requested[0] ?? null))['locales'];
        }

        $profile = app(TenantContext::class)->tenant()?->teacherProfile;

        return LandingSchema::normalizeLocales($profile?->locales, $profile?->primary_locale)['locales'];
    }
}
