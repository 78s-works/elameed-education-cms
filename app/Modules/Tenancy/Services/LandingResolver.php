<?php

namespace App\Modules\Tenancy\Services;

use App\Models\User;
use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Commerce\Enums\EnrollmentStatus;
use App\Modules\Commerce\Models\Enrollment;
use App\Modules\Engagement\Models\Review;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Tenancy\Models\TeacherProfile;
use App\Modules\Tenancy\Support\LandingSchema;
use Illuminate\Support\Collection;

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
        $courseIds = [];
        foreach ($payload['sections'] ?? [] as $section) {
            if (($section['type'] ?? null) === 'courses') {
                foreach ($section['items'] ?? [] as $item) {
                    if (isset($item['id'])) {
                        $courseIds[] = (int) $item['id'];
                    }
                }
            }
        }

        if ($courseIds === []) {
            return $payload;
        }

        $enrolled = array_flip(
            $this->viewerEnrolledIds($tenantId, (int) $viewer->getKey(), array_values(array_unique($courseIds)))
        );

        foreach ($payload['sections'] as &$section) {
            if (($section['type'] ?? null) !== 'courses') {
                continue;
            }
            foreach ($section['items'] as &$item) {
                $item['enrolled'] = isset($enrolled[(int) $item['id']]);
            }
            unset($item);
        }
        unset($section);

        return $payload;
    }

    /** @return array<int, array<string, mixed>> */
    private function resolveCourses(int $tenantId, array $config): array
    {
        $source = $config['source'] ?? 'featured';
        $limit = max(1, min(24, (int) ($config['limit'] ?? 6)));

        $courses = $this->baseCourses($tenantId, $source, $config);
        if ($courses->isEmpty()) {
            return [];
        }

        $ids = $courses->pluck('id')->all();
        $students = $this->activeEnrollmentCounts($tenantId, $ids);
        $lessons = $this->lessonAggregates($tenantId, $ids);
        $ratings = $this->ratingAverages($tenantId, $ids);

        if ($source === 'featured') {
            $courses = $courses->sortByDesc(fn ($c) => $students[$c->id] ?? 0)->values();
        }

        $shown = $courses->take($limit)->values();

        // Card-image fallback chain: course cover → course thumbnail → first
        // published lesson's video poster. Only queried for courses that have
        // NEITHER own image, so a coverless course still shows a real thumbnail
        // instead of a placeholder.
        $needyIds = $shown
            ->filter(fn (Course $c) => empty($c->cover_url) && empty($c->thumbnail_url))
            ->pluck('id')->all();
        $posters = $needyIds !== [] ? $this->lessonPosters($tenantId, $needyIds) : [];

        return $shown->map(fn (Course $c) => [
            'id' => $c->id,
            'uuid' => $c->uuid,
            'slug' => $c->slug,
            'title' => $c->title,
            'cover_url' => $c->cover_url ?: ($c->thumbnail_url ?: ($posters[$c->id] ?? null)),
            'thumbnail_url' => $c->thumbnail_url,
            'grade' => $c->category?->grade ?? $c->category?->name,
            'type' => $c->is_center ? 'center' : 'online',
            'price' => ['amount_minor' => (int) $c->price_minor, 'currency' => $c->currency],
            'is_free' => (bool) $c->is_free,
            'lessons_count' => (int) ($lessons[$c->id]->c ?? 0),
            'duration_label' => $this->durationLabel((int) ($lessons[$c->id]->d ?? 0)),
            'rating' => isset($ratings[$c->id]) ? round((float) $ratings[$c->id], 1) : null,
            'students_count' => (int) ($students[$c->id] ?? 0),
            // Viewer-agnostic base; applyEnrollment() flips this per student.
            'enrolled' => false,
        ])->all();
    }

    /**
     * Per-course poster fallback: for each course id, the `thumbnail_url` of the
     * first published lesson (in order) whose video actually has a poster.
     * Batched into two queries regardless of course count.
     *
     * @param  list<int>  $courseIds
     * @return array<int, string> course_id => thumbnail_url
     */
    private function lessonPosters(int $tenantId, array $courseIds): array
    {
        // Published, video-bearing lessons for these courses, in display order.
        $lessons = Lesson::withoutGlobalScopes()
            ->published()
            ->where('tenant_id', $tenantId)
            ->whereIn('course_id', $courseIds)
            ->whereNotNull('video_asset_id')
            ->orderBy('sort_order')->orderBy('id')
            ->get(['course_id', 'video_asset_id']);

        if ($lessons->isEmpty()) {
            return [];
        }

        // Only the video assets that actually carry a poster.
        $thumbs = MediaAsset::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $lessons->pluck('video_asset_id')->unique()->values()->all())
            ->whereNotNull('thumbnail_url')
            ->pluck('thumbnail_url', 'id');

        $posters = [];
        foreach ($lessons as $lesson) {
            if (isset($posters[$lesson->course_id])) {
                continue; // keep the first (earliest) poster found for the course
            }
            if (isset($thumbs[$lesson->video_asset_id])) {
                $posters[$lesson->course_id] = $thumbs[$lesson->video_asset_id];
            }
        }

        return $posters;
    }

    private function baseCourses(int $tenantId, string $source, array $config): Collection
    {
        if ($source === 'selected') {
            $ids = array_values(array_map('intval', (array) ($config['course_ids'] ?? [])));
            if ($ids === []) {
                return collect();
            }
            // published() too: a hand-picked course that is later unpublished /
            // archived must NOT keep leaking onto the public landing.
            $found = Course::query()->published()->whereIn('id', $ids)->with('category')->get()->keyBy('id');

            // Preserve the teacher's chosen order.
            return collect($ids)->map(fn ($id) => $found->get($id))->filter()->values();
        }

        $query = Course::query()->published()->with('category');

        if ($source === 'category' && ! empty($config['category_id'])) {
            $query->where('category_id', (int) $config['category_id']);
        }

        // `featured` needs counts before ranking, so pull a bounded set; others
        // just take the newest.
        return $query->latest('id')->limit($source === 'featured' ? 60 : 24)->get();
    }

    /** @return array<int, array<string, mixed>> */
    private function resolveReviews(int $tenantId, array $config): array
    {
        $source = $config['source'] ?? 'latest';
        $limit = max(1, min(24, (int) ($config['limit'] ?? 6)));

        $query = Review::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with(['user:id,name', 'course:id,title']);

        if ($source === 'top_rated') {
            $query->where('rating', '>=', (int) ($config['min_rating'] ?? 0))
                ->orderByDesc('rating')->orderByDesc('created_at');
        } else {
            $query->latest();
        }

        return $query->limit($limit)->get()->map(fn (Review $r) => [
            'id' => $r->id,
            'student_name' => $r->user?->name,
            'course_title' => $r->course?->title,
            'rating' => $r->rating,
            'comment' => $r->comment,
            'created_at' => $r->created_at?->toIso8601String(),
        ])->all();
    }

    private function activeEnrollmentCounts(int $tenantId, array $ids): array
    {
        return Enrollment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->whereIn('course_id', $ids)
            ->where('status', EnrollmentStatus::Active->value)
            ->selectRaw('course_id, count(*) as c')->groupBy('course_id')
            ->pluck('c', 'course_id')->all();
    }

    private function lessonAggregates(int $tenantId, array $ids): array
    {
        return Lesson::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->whereIn('course_id', $ids)
            ->selectRaw('course_id, count(*) as c, coalesce(sum(duration_sec),0) as d')
            ->groupBy('course_id')->get()->keyBy('course_id')->all();
    }

    private function ratingAverages(int $tenantId, array $ids): array
    {
        return Review::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->whereIn('course_id', $ids)
            ->selectRaw('course_id, avg(rating) as r')->groupBy('course_id')
            ->pluck('r', 'course_id')->all();
    }

    private function viewerEnrolledIds(int $tenantId, int $userId, array $ids): array
    {
        return Enrollment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('user_id', $userId)->whereIn('course_id', $ids)
            ->where('status', EnrollmentStatus::Active->value)
            ->pluck('course_id')->map(fn ($v) => (int) $v)->all();
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
