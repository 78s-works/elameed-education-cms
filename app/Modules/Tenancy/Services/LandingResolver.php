<?php

namespace App\Modules\Tenancy\Services;

use App\Models\User;
use App\Modules\Catalog\Enums\AccessMode;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Commerce\Enums\EnrollmentStatus;
use App\Modules\Commerce\Models\Enrollment;
use App\Modules\Engagement\Models\Review;
use App\Modules\Tenancy\Models\TeacherProfile;
use App\Modules\Tenancy\Support\LandingSchema;

/**
 * Resolves a teacher's stored landing config into the fully-rendered public
 * payload (LANDING_CONTRACT_V2.md): normalizes layout + sections, resolves the
 * dynamic `courses`/`testimonials` sections to real `items`, and derives `nav`.
 */
class LandingResolver
{
    /** Sections that get an anchor link in the top nav. */
    private const NAV_TYPES = ['about', 'features', 'courses', 'steps', 'testimonials', 'packages', 'contact'];

    /**
     * Resolve the stored config into the public payload. Viewer-agnostic on
     * purpose: the result is safe to cache once per tenant and share across all
     * visitors. Per-student `enrolled` flags are layered on afterwards by
     * applyEnrollment() so caching never mixes one student's state into another's.
     */
    public function resolve(int $tenantId, ?TeacherProfile $profile): array
    {
        $meta = LandingSchema::normalizeLocales($profile?->locales, $profile?->primary_locale);
        $locales = $meta['locales'];
        $primary = $meta['primary'];

        $stored = ($profile && $profile->landing_sections) ? $profile->landing_sections : LandingSchema::defaults($primary);

        $sections = [];
        foreach (array_values($stored) as $i => $s) {
            $type = $s['type'] ?? $s['key'] ?? null;
            if (! is_string($type) || ! in_array($type, LandingSchema::TYPES, true)) {
                continue; // skip stale/unknown (e.g. old v1 'offers'/'faq')
            }

            $entry = [
                'key' => $s['key'] ?? $type,
                'type' => $type,
                // Per-section layout; defaults to the type's first variant when a
                // stored (e.g. pre-variant) section carries none.
                'variant' => LandingSchema::variantOrDefault($type, is_string($s['variant'] ?? null) ? $s['variant'] : null),
                'visible' => (bool) ($s['visible'] ?? true),
                'order' => (int) ($s['order'] ?? ($i + 1)),
                // Per-locale content (all enabled locales, primary-filled).
                'content' => $this->localizeContent((array) ($s['content'] ?? []), $locales, $primary),
            ];

            if ($type === 'courses') {
                $entry['items'] = $this->resolveCourses($tenantId, (array) ($s['config'] ?? []));
            } elseif ($type === 'testimonials') {
                $entry['items'] = $this->resolveReviews($tenantId, (array) ($s['config'] ?? []));
            }

            $sections[] = $entry;
        }

        usort($sections, fn ($a, $b) => $a['order'] <=> $b['order']);

        return [
            'layout' => $this->normalizeLayout($profile?->layout),
            'locales' => $locales,
            'primary_locale' => $primary,
            'nav' => ['links' => $this->buildNav($sections, $locales, $primary)],
            'sections' => $sections,
        ];
    }

    /**
     * Expand a stored content block to a per-locale map covering every enabled
     * locale. Already locale-keyed content is filled from the primary for any
     * missing locale; flat (legacy/pre-i18n) content is treated as the primary's.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, array<string, mixed>>
     */
    private function localizeContent(array $content, array $locales, string $primary): array
    {
        $localeKeyed = false;
        foreach ($locales as $l) {
            if (array_key_exists($l, $content)) {
                $localeKeyed = true;
                break;
            }
        }

        $map = $localeKeyed ? $content : [$primary => $content];

        $out = [];
        foreach ($locales as $l) {
            $out[$l] = (array) ($map[$l] ?? $map[$primary] ?? []);
        }

        return $out;
    }

    public function normalizeLayout(?string $layout): string
    {
        return in_array($layout, LandingSchema::LAYOUTS, true) ? $layout : 'classic';
    }

    /**
     * Derive anchor-nav links from visible, nav-worthy sections. Labels are a
     * per-locale map so the SPA can render the nav in the active language; each
     * locale falls back to a capitalized type name when that section has no title.
     *
     * Accepts sections whose `content` is either already localized (public
     * resolve output) or stored per-locale (teacher editor) — both are keyed by
     * locale.
     */
    public function buildNav(array $sections, array $locales, string $primary): array
    {
        $locales = LandingSchema::orderedLocales($locales, $primary);

        $links = [];
        foreach ($sections as $s) {
            if (! ($s['visible'] ?? true) || ! in_array($s['type'] ?? null, self::NAV_TYPES, true)) {
                continue;
            }

            $content = (array) ($s['content'] ?? []);
            $label = [];
            foreach ($locales as $l) {
                $title = $content[$l]['title'] ?? null;
                $label[$l] = ($title !== null && $title !== '') ? $title : ucfirst((string) $s['type']);
            }

            $links[] = ['label' => $label, 'target' => '#'.($s['key'] ?? $s['type'])];
        }

        return $links;
    }

    /**
     * Overlay the viewer's active enrollments onto an already-resolved payload.
     * Kept separate from resolve() so the (viewer-agnostic) base payload can be
     * cached once per tenant; this runs per request for the authenticated
     * student only, touching one query for all course ids in the payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function applyEnrollment(array $payload, int $tenantId, User $viewer): array
    {
        // The "courses" section lists standalone lessons now (VD §7); overlay the
        // viewer's per-lesson access onto each card by its `lesson_id`.
        $lessonIds = [];
        foreach ($payload['sections'] ?? [] as $section) {
            if (($section['type'] ?? null) === 'courses') {
                foreach ($section['items'] ?? [] as $item) {
                    if (isset($item['lesson_id'])) {
                        $lessonIds[] = (int) $item['lesson_id'];
                    }
                }
            }
        }

        if ($lessonIds === []) {
            return $payload;
        }

        $enrolled = array_flip(
            $this->viewerEnrolledIds($tenantId, (int) $viewer->getKey(), array_values(array_unique($lessonIds)))
        );

        foreach ($payload['sections'] as &$section) {
            if (($section['type'] ?? null) !== 'courses') {
                continue;
            }
            foreach ($section['items'] as &$item) {
                $item['enrolled'] = isset($item['lesson_id']) && isset($enrolled[(int) $item['lesson_id']]);
            }
            unset($item);
        }
        unset($section);

        return $payload;
    }

    /**
     * The landing "courses" section now lists standalone LESSONS (VD §7 — the
     * course system is retired; content is lessons + parts). Cards carry
     * `kind:'lesson'` + `lesson_id` so the SPA links them to checkout (lessons
     * have no public detail page). The `id` is a synthetic `lesson-<id>` string so
     * it never collides with course-enrollment ids in applyEnrollment().
     *
     * Honors the stored `config`:
     *   - source=selected → only the hand-picked `course_ids` (LESSON ids), kept
     *     in the teacher's chosen order.
     *   - source=featured|all|category → all published, purchasable lessons in
     *     sort order. `category` behaves like `all`: lessons carry no category
     *     (categories were a course concept, retired VD §7), so there is nothing
     *     to filter by — the source stays in the contract only for back-compat.
     *   - limit clamps the result to 1..24.
     *
     * @return array<int, array<string, mixed>>
     */
    private function resolveCourses(int $tenantId, array $config): array
    {
        $source = $config['source'] ?? 'featured';
        $limit = max(1, min(24, (int) ($config['limit'] ?? 6)));
        $ids = array_values(array_filter(array_map('intval', (array) ($config['course_ids'] ?? []))));

        $query = Lesson::withoutGlobalScopes()
            ->published()
            ->where('tenant_id', $tenantId)
            ->where('is_purchasable', true);

        if ($source === 'selected') {
            if ($ids === []) {
                return [];
            }
            // Fetch the picked lessons, then re-order to match the teacher's
            // selection order before clamping to the limit.
            $position = array_flip($ids);
            $lessons = $query->whereIn('id', $ids)->get()
                ->sortBy(fn (Lesson $l) => $position[$l->id] ?? PHP_INT_MAX)
                ->take($limit)
                ->values();
        } else {
            $lessons = $query->orderBy('sort_order')->orderBy('id')->limit($limit)->get();
        }

        if ($lessons->isEmpty()) {
            return [];
        }

        return $lessons->map(fn (Lesson $l) => [
            'id' => 'lesson-'.$l->id,
            'lesson_id' => $l->id,
            'kind' => 'lesson',
            'title' => $l->title,
            'cover_url' => null,
            'grade' => null,
            'type' => $l->access_mode === AccessMode::Center ? 'center' : 'online',
            'price' => ['amount_minor' => (int) $l->price_minor, 'currency' => $l->currency],
            'is_free' => (int) $l->price_minor === 0,
            'lessons_count' => 0,
            'duration_label' => $this->durationLabel((int) $l->duration_sec),
            'rating' => null,
            'students_count' => 0,
            'enrolled' => false,
        ])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function resolveReviews(int $tenantId, array $config): array
    {
        $source = $config['source'] ?? 'latest';
        $limit = max(1, min(24, (int) ($config['limit'] ?? 6)));

        $query = Review::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_visible', true)       // only teacher-approved reviews reach the landing
            ->with('user:id,name');

        if ($source === 'top_rated') {
            $query->where('rating', '>=', (int) ($config['min_rating'] ?? 0))
                ->orderByDesc('rating')->orderByDesc('created_at');
        } else {
            $query->latest();
        }

        return $query->limit($limit)->get()->map(function (Review $r): array {
            // Review targets a lesson|package now (VD §7); resolve its display title.
            $target = $r->target();

            return [
                'id' => $r->id,
                'student_name' => $r->displayName(),
                'target_type' => $r->target_type,
                'target_id' => $r->target_id,
                'target_title' => $target?->title ?? $target?->name,
                'rating' => $r->rating,
                'comment' => $r->comment,
                'created_at' => $r->created_at?->toIso8601String(),
            ];
        })->all();
    }

    /** Lesson ids the viewer currently holds an access grant for (VD §7 — per-lesson access). */
    private function viewerEnrolledIds(int $tenantId, int $userId, array $lessonIds): array
    {
        return Enrollment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('user_id', $userId)->whereIn('lesson_id', $lessonIds)
            ->where('status', EnrollmentStatus::Active->value)
            ->pluck('lesson_id')->map(fn ($v) => (int) $v)->all();
    }

    private function durationLabel(int $seconds): ?string
    {
        if ($seconds <= 0) {
            return null;
        }
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);

        return $h > 0 ? trim("{$h}h ".($m > 0 ? "{$m}m" : '')) : "{$m}m";
    }
}
