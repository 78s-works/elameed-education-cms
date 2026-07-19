<?php

namespace App\Modules\Tenancy\Support;

use Illuminate\Validation\Rule;

/**
 * The landing content contract v2 (elameed-education-cms/docs/LANDING_CONTRACT_V2.md).
 *
 * A FIXED catalog of typed sections. Two sections are DYNAMIC (`courses`,
 * `testimonials`): the teacher stores a `config` rule; the public endpoint
 * resolves it to real `items` (see LandingResolver). All three layouts
 * (classic|grid|spotlight) consume this identical contract.
 *
 * Per the milestone scope, `stats|features|steps|packages` only expose
 * title/subtitle for editing — their `items` are preserved from the last save.
 */
final class LandingSchema
{
    public const LAYOUTS = ['classic', 'grid', 'spotlight'];

    public const TYPES = ['hero', 'stats', 'features', 'about', 'steps', 'courses', 'testimonials', 'packages', 'cta', 'contact'];

    /** Server-resolved sections (store `config`, emit `items`). */
    public const DYNAMIC = ['courses', 'testimonials'];

    /** Their `content.items` are not editable this milestone — preserved on save. */
    public const ITEM_PRESERVED = ['stats', 'features', 'steps', 'packages'];

    /** Editable `content` field rules per type. */
    public static function contentRules(string $type): array
    {
        return match ($type) {
            'hero' => [
                'eyebrow' => ['nullable', 'string', 'max:120'],
                'title_html' => ['nullable', 'string', 'max:400'],
                'description' => ['nullable', 'string', 'max:1000'],
                'note' => ['nullable', 'string', 'max:300'],
                'primary_cta' => ['nullable', 'array'],
                'primary_cta.label' => ['nullable', 'string', 'max:60'],
                'secondary_cta' => ['nullable', 'array'],
                'secondary_cta.label' => ['nullable', 'string', 'max:60'],
                'teacher' => ['nullable', 'array'],
                'teacher.name' => ['nullable', 'string', 'max:120'],
                'teacher.role' => ['nullable', 'string', 'max:120'],
                'teacher.image_url' => ['nullable', 'string', 'max:2048'],
                'teacher.card_stats' => ['nullable', 'array', 'max:6'],
                'teacher.card_stats.*.value' => ['required', 'string', 'max:40'],
                'teacher.card_stats.*.label' => ['required', 'string', 'max:60'],
                'chips' => ['nullable', 'array', 'max:8'],
                'chips.*.text' => ['required', 'string', 'max:60'],
                'chips.*.type' => ['nullable', Rule::in(['green', 'red', 'plain'])],
            ],
            'about' => [
                'badge' => ['nullable', 'string', 'max:60'],
                'title' => ['nullable', 'string', 'max:200'],
                'body' => ['nullable', 'string', 'max:5000'],
                'image_url' => ['nullable', 'string', 'max:2048'],
                'points' => ['nullable', 'array', 'max:20'],
                'points.*' => ['string', 'max:300'],
            ],
            'cta' => [
                'title' => ['nullable', 'string', 'max:200'],
                'subtitle' => ['nullable', 'string', 'max:400'],
                'cta' => ['nullable', 'array'],
                'cta.label' => ['nullable', 'string', 'max:60'],
            ],
            // Dynamic + item-preserved + contact: only title/subtitle are editable.
            'courses', 'testimonials', 'features', 'steps', 'packages', 'contact' => [
                'title' => ['nullable', 'string', 'max:200'],
                'subtitle' => ['nullable', 'string', 'max:400'],
            ],
            default => [], // stats: nothing editable this milestone
        };
    }

    /** `config` rules for a dynamic section (empty for static types). */
    public static function configRules(string $type): array
    {
        return match ($type) {
            'courses' => [
                'config' => ['required', 'array'],
                'config.source' => ['required', Rule::in(['featured', 'all', 'category', 'selected'])],
                'config.category_id' => ['nullable', 'integer'],
                'config.course_ids' => ['nullable', 'array', 'max:24'],
                'config.course_ids.*' => ['integer'],
                'config.limit' => ['nullable', 'integer', 'min:1', 'max:24'],
            ],
            'testimonials' => [
                'config' => ['required', 'array'],
                'config.source' => ['required', Rule::in(['latest', 'top_rated'])],
                'config.min_rating' => ['nullable', 'integer', 'min:0', 'max:5'],
                'config.limit' => ['nullable', 'integer', 'min:1', 'max:24'],
            ],
            default => [],
        };
    }

    /** Top-level editable content field names for a type. */
    public static function contentFields(string $type): array
    {
        $fields = [];
        foreach (array_keys(self::contentRules($type)) as $path) {
            $fields[strtok($path, '.')] = true;
        }

        return array_keys($fields);
    }

    /** The platform-supported UI locales a teacher may enable (config-driven). */
    public static function supportedLocales(): array
    {
        $supported = array_values(array_filter(array_map(
            static fn ($l): string => strtolower(trim((string) $l)),
            (array) config('tenancy.supported_locales', ['ar', 'en'])
        )));

        return $supported ?: ['ar'];
    }

    /**
     * Clamp a teacher's requested locale set to what the platform supports and
     * guarantee a valid primary within it.
     *
     * @return array{locales: list<string>, primary: string}
     */
    public static function normalizeLocales(?array $locales, ?string $primary): array
    {
        $supported = self::supportedLocales();
        $default = strtolower((string) config('tenancy.default_locale', 'ar'));

        $locales = array_values(array_intersect(
            array_map(static fn ($l): string => strtolower(trim((string) $l)), (array) ($locales ?: [])),
            $supported
        ));

        if ($locales === []) {
            $locales = [in_array($default, $supported, true) ? $default : $supported[0]];
        }

        $primary = strtolower(trim((string) $primary));
        if (! in_array($primary, $locales, true)) {
            $primary = $locales[0];
        }

        return ['locales' => self::orderedLocales($locales, $primary), 'primary' => $primary];
    }

    /** Unique, primary-first ordering of a locale list. @return list<string> */
    public static function orderedLocales(array $locales, string $primary): array
    {
        $locales = array_values(array_unique(array_filter(
            array_map(static fn ($l): string => strtolower(trim((string) $l)), $locales),
            static fn (string $l): bool => $l !== ''
        )));

        $locales = array_values(array_diff($locales, [$primary]));
        array_unshift($locales, $primary);

        return array_values(array_unique($locales));
    }

    /**
     * Normalize submitted sections: keep known types only, keep just the editable
     * content fields PER LOCALE, preserve `items` per locale for item-preserved
     * types (from the previously-saved section with the same key), keep `config`
     * for dynamic, and guarantee unique keys (needed for nav anchors when the
     * teacher duplicates a section type).
     *
     * @param  array<int, mixed>  $incoming
     * @param  array<int, mixed>  $existing  previously saved sections (for item preservation)
     * @param  list<string>  $locales  the enabled, primary-first locales
     */
    public static function sanitize(array $incoming, array $existing, array $locales, string $primary): array
    {
        $locales = self::orderedLocales($locales, $primary);

        $prev = [];
        foreach ($existing as $s) {
            if (isset($s['key'])) {
                $prev[$s['key']] = $s;
            }
        }

        $clean = [];
        $usedKeys = [];
        foreach (array_values($incoming) as $i => $section) {
            $type = $section['type'] ?? null;
            if (! is_string($type) || ! in_array($type, self::TYPES, true)) {
                continue;
            }

            $originalKey = is_string($section['key'] ?? null) && $section['key'] !== '' ? $section['key'] : $type;
            $key = self::uniqueKey($originalKey, $usedKeys);
            $usedKeys[$key] = true;

            $incomingContent = (array) ($section['content'] ?? []);
            $prevContent = (array) ($prev[$originalKey]['content'] ?? []);

            $content = [];
            foreach ($locales as $locale) {
                $content[$locale] = self::sanitizeLocaleContent(
                    $type,
                    (array) ($incomingContent[$locale] ?? []),
                    (array) ($prevContent[$locale] ?? []),
                );
            }

            $entry = [
                'key' => $key,
                'type' => $type,
                'visible' => (bool) ($section['visible'] ?? true),
                'order' => (int) ($section['order'] ?? ($i + 1)),
                'content' => $content,
            ];

            if (in_array($type, self::DYNAMIC, true)) {
                $entry['config'] = self::cleanConfig($type, (array) ($section['config'] ?? []));
            }

            $clean[] = $entry;
        }

        usort($clean, fn ($a, $b) => $a['order'] <=> $b['order']);

        return $clean;
    }

    /** Sanitize one locale's content block for a section type. */
    private static function sanitizeLocaleContent(string $type, array $incoming, array $prev): array
    {
        $content = [];
        foreach (self::contentFields($type) as $field) {
            if (array_key_exists($field, $incoming)) {
                $content[$field] = $incoming[$field];
            }
        }

        // hero.title_html renders as HTML on a public page — allow only bare <span>.
        if ($type === 'hero' && isset($content['title_html'])) {
            $content['title_html'] = self::cleanTitleHtml((string) $content['title_html']);
        }

        // Preserve non-editable item lists (per locale) from the last save.
        if (in_array($type, self::ITEM_PRESERVED, true)) {
            $content['items'] = $prev['items'] ?? ($incoming['items'] ?? []);
        }

        return $content;
    }

    /** Ensure a section key is unique within the save (append -2, -3, … on clash). */
    private static function uniqueKey(string $key, array $used): string
    {
        if (! isset($used[$key])) {
            return $key;
        }

        $n = 2;
        while (isset($used[$key.'-'.$n])) {
            $n++;
        }

        return $key.'-'.$n;
    }

    private static function cleanTitleHtml(string $html): string
    {
        $html = strip_tags($html, '<span>');

        return (string) preg_replace('/<span[^>]*>/i', '<span>', $html); // drop any attributes
    }

    private static function cleanConfig(string $type, array $config): array
    {
        if ($type === 'courses') {
            return [
                'source' => $config['source'] ?? 'featured',
                'category_id' => $config['category_id'] ?? null,
                'course_ids' => array_values(array_map('intval', (array) ($config['course_ids'] ?? []))),
                'limit' => (int) ($config['limit'] ?? 6),
            ];
        }

        return [
            'source' => $config['source'] ?? 'latest',
            'min_rating' => (int) ($config['min_rating'] ?? 0),
            'limit' => (int) ($config['limit'] ?? 6),
        ];
    }

    /**
     * A sensible starter landing (used for seeding + as a fallback when unset).
     * Each section's content is wrapped under the primary locale so the shape
     * matches saved data ({ <locale>: {...fields} }).
     */
    public static function defaults(?string $primary = null): array
    {
        $primary = $primary ?: strtolower((string) config('tenancy.default_locale', 'ar'));

        return array_map(static function (array $section) use ($primary): array {
            $section['content'] = [$primary => $section['content']];

            return $section;
        }, self::defaultSections());
    }

    /** The flat (single-language) default sections, before per-locale wrapping. */
    private static function defaultSections(): array
    {
        return [
            ['key' => 'hero', 'type' => 'hero', 'visible' => true, 'order' => 1, 'content' => [
                'eyebrow' => '', 'title_html' => '', 'description' => '', 'note' => '',
                'primary_cta' => ['label' => 'ابدأ الآن'], 'secondary_cta' => ['label' => 'تصفّح الكورسات'],
                'teacher' => ['name' => '', 'role' => '', 'image_url' => null, 'card_stats' => []],
                'chips' => [],
            ]],
            ['key' => 'stats', 'type' => 'stats', 'visible' => true, 'order' => 2, 'content' => ['items' => []]],
            ['key' => 'features', 'type' => 'features', 'visible' => true, 'order' => 3, 'content' => ['title' => '', 'subtitle' => '', 'items' => []]],
            ['key' => 'about', 'type' => 'about', 'visible' => true, 'order' => 4, 'content' => ['badge' => '', 'title' => '', 'body' => '', 'image_url' => null, 'points' => []]],
            ['key' => 'courses', 'type' => 'courses', 'visible' => true, 'order' => 5, 'content' => ['title' => 'الكورسات', 'subtitle' => ''], 'config' => ['source' => 'featured', 'category_id' => null, 'course_ids' => [], 'limit' => 6]],
            ['key' => 'how', 'type' => 'steps', 'visible' => true, 'order' => 6, 'content' => ['title' => '', 'subtitle' => '', 'items' => []]],
            ['key' => 'testimonials', 'type' => 'testimonials', 'visible' => true, 'order' => 7, 'content' => ['title' => 'آراء الطلاب', 'subtitle' => ''], 'config' => ['source' => 'latest', 'min_rating' => 0, 'limit' => 6]],
            ['key' => 'packages', 'type' => 'packages', 'visible' => false, 'order' => 8, 'content' => ['title' => '', 'subtitle' => '', 'items' => []]],
            ['key' => 'cta', 'type' => 'cta', 'visible' => true, 'order' => 9, 'content' => ['title' => '', 'subtitle' => '', 'cta' => ['label' => 'اشترك الآن']]],
            ['key' => 'contact', 'type' => 'contact', 'visible' => true, 'order' => 10, 'content' => ['title' => 'تواصل معنا', 'subtitle' => '']],
        ];
    }
}
