<?php

namespace App\Modules\Tenancy\Services;

use App\Models\User;
use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Commerce\Enums\EnrollmentStatus;
use App\Modules\Commerce\Models\Enrollment;
use App\Modules\Engagement\Models\Review;
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

    public function resolve(int $tenantId, ?TeacherProfile $profile, ?User $viewer): array
    {
        $stored = ($profile && $profile->landing_sections) ? $profile->landing_sections : LandingSchema::defaults();

        $sections = [];
        foreach (array_values($stored) as $i => $s) {
            $type = $s['type'] ?? $s['key'] ?? null;
            if (! is_string($type) || ! in_array($type, LandingSchema::TYPES, true)) {
                continue; // skip stale/unknown (e.g. old v1 'offers'/'faq')
            }

            $entry = [
                'key' => $s['key'] ?? $type,
                'type' => $type,
                'visible' => (bool) ($s['visible'] ?? true),
                'order' => (int) ($s['order'] ?? ($i + 1)),
                'content' => (array) ($s['content'] ?? []),
            ];

            if ($type === 'courses') {
                $entry['items'] = $this->resolveCourses($tenantId, (array) ($s['config'] ?? []), $viewer);
            } elseif ($type === 'testimonials') {
                $entry['items'] = $this->resolveReviews($tenantId, (array) ($s['config'] ?? []));
            }

            $sections[] = $entry;
        }

        usort($sections, fn ($a, $b) => $a['order'] <=> $b['order']);

        return [
            'layout' => $this->normalizeLayout($profile?->layout),
            'nav' => ['links' => $this->buildNav($sections)],
            'sections' => $sections,
        ];
    }

    public function normalizeLayout(?string $layout): string
    {
        return in_array($layout, LandingSchema::LAYOUTS, true) ? $layout : 'classic';
    }

    /** Derive anchor-nav links from visible, nav-worthy sections. */
    public function buildNav(array $sections): array
    {
        $links = [];
        foreach ($sections as $s) {
            if (! $s['visible'] || ! in_array($s['type'], self::NAV_TYPES, true)) {
                continue;
            }
            $label = $s['content']['title'] ?? null;
            $links[] = ['label' => $label !== null && $label !== '' ? $label : ucfirst($s['type']), 'target' => '#'.$s['key']];
        }

        return $links;
    }

    /** @return array<int, array<string, mixed>> */
    private function resolveCourses(int $tenantId, array $config, ?User $viewer): array
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
        $enrolledIds = $viewer ? $this->viewerEnrolledIds($tenantId, (int) $viewer->getKey(), $ids) : [];

        if ($source === 'featured') {
            $courses = $courses->sortByDesc(fn ($c) => $students[$c->id] ?? 0)->values();
        }

        return $courses->take($limit)->map(fn (Course $c) => [
            'id' => $c->id,
            'uuid' => $c->uuid,
            'slug' => $c->slug,
            'title' => $c->title,
            'cover_url' => $c->cover_url,
            'grade' => $c->category?->grade ?? $c->category?->name,
            'type' => $c->is_center ? 'center' : 'online',
            'price' => ['amount_minor' => (int) $c->price_minor, 'currency' => $c->currency],
            'is_free' => (bool) $c->is_free,
            'lessons_count' => (int) ($lessons[$c->id]->c ?? 0),
            'duration_label' => $this->durationLabel((int) ($lessons[$c->id]->d ?? 0)),
            'rating' => isset($ratings[$c->id]) ? round((float) $ratings[$c->id], 1) : null,
            'students_count' => (int) ($students[$c->id] ?? 0),
            'enrolled' => in_array($c->id, $enrolledIds, true),
        ])->values()->all();
    }

    private function baseCourses(int $tenantId, string $source, array $config): Collection
    {
        if ($source === 'selected') {
            $ids = array_values(array_map('intval', (array) ($config['course_ids'] ?? [])));
            if ($ids === []) {
                return collect();
            }
            $found = Course::query()->whereIn('id', $ids)->with('category')->get()->keyBy('id');

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
