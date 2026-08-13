<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Enums\AccessMode;
use App\Modules\Catalog\Http\Resources\CourseDetailResource;
use App\Modules\Catalog\Http\Resources\CourseResource;
use App\Modules\Catalog\Http\Resources\LessonResource;
use App\Modules\Catalog\Http\Resources\PackageResource;
use App\Modules\Catalog\Http\Resources\PackageTypeResource;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Package;
use App\Modules\Catalog\Models\PackageType;
use App\Modules\Catalog\Services\StudentPartVisibility;
use App\Modules\Commerce\Models\Enrollment;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Public catalogue for the resolved tenant (GET /courses, /courses/{slug}).
 * Only published (visible + due) content is returned; tenant isolation is via
 * the BelongsToTenant scope. No auth.
 *
 * GET /courses serves the three discovery granularities (VD R8 / doc 12 §7 LP-9):
 *   - default          → published courses (unchanged; backward compatible).
 *   - ?view=lessons     → published, individually-purchasable standalone lessons.
 *   - ?view=packages    → purchasable recursive content packages.
 *
 * All three accept ?access_mode=center|online|both (channel filter, wildcard on
 * `both` via {@see AccessMode::isVisibleTo}) and — for the year-scoped lessons/
 * packages views — an optional ?academic_year=<uuid> narrowing.
 */
class PublicCatalogController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly StudentPartVisibility $studyMode,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'view' => ['sometimes', Rule::in(['courses', 'lessons', 'packages'])],
            'access_mode' => ['sometimes', Rule::enum(AccessMode::class)],
        ]);

        return match ((string) $request->string('view')) {
            'lessons' => $this->lessons($request),
            'packages' => $this->packages($request),
            default => $this->courses($request),
        };
    }

    public function show(Course $course): CourseDetailResource
    {
        // Route binding scopes to the tenant; hidden/scheduled courses 404 publicly.
        abort_unless($course->isPublished(), 404);

        $course->load([
            'category',
            // Units retired (VD §7): the course's published lessons are loaded
            // directly (those still linked by lesson.course_id).
            'lessons' => fn ($q) => $q->published()->orderBy('sort_order'),
        ]);

        return new CourseDetailResource($course);
    }

    /** Default view — published courses of the tenant. */
    private function courses(Request $request): AnonymousResourceCollection
    {
        $courses = Course::query()
            ->published()
            ->with('category')
            ->when($request->input('filter.category_id'), fn ($q, $id) => $q->where('category_id', $id))
            ->when($request->input('filter.grade'), fn ($q, $grade) => $q->whereHas('category', fn ($c) => $c->where('grade', $grade)))
            ->when($request->input('filter.subject'), fn ($q, $subject) => $q->whereHas('category', fn ($c) => $c->where('subject', $subject)))
            ->when($request->input('q'), fn ($q, $term) => $q->where('title', 'like', '%'.$term.'%'))
            ->tap(fn (Builder $q) => $this->applyAccessMode($q, $request))
            ->latest()
            ->paginate(20);

        return CourseResource::collection($courses);
    }

    /** view=lessons — published, individually-purchasable standalone lessons (R8 "single lectures"). */
    private function lessons(Request $request): AnonymousResourceCollection
    {
        $lessons = Lesson::query()
            ->published()
            ->where('is_purchasable', true)
            ->when($request->input('q'), fn ($q, $term) => $q->where('title', 'like', '%'.$term.'%'))
            ->tap(fn (Builder $q) => $this->applyAccessMode($q, $request))
            ->tap(fn (Builder $q) => $this->applyAcademicYear($q, $request))
            ->tap(fn (Builder $q) => $this->applyExcludeOwned($q, $request, 'lesson_id'))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(20);

        return LessonResource::collection($lessons);
    }

    /** view=packages — purchasable recursive content packages (R8 modules/bundles). */
    private function packages(Request $request): AnonymousResourceCollection
    {
        $packages = Package::query()
            ->where('is_purchasable', true)
            ->when($request->input('q'), fn ($q, $term) => $q->where('name', 'like', '%'.$term.'%'))
            // B27 package-type filter (bind by the type's public uuid).
            ->when($request->input('package_type'), fn ($q, $uuid) => $q->whereHas(
                'packageType',
                fn (Builder $t) => $t->where('uuid', $uuid),
            ))
            ->tap(fn (Builder $q) => $this->applyAccessMode($q, $request))
            ->tap(fn (Builder $q) => $this->applyAcademicYear($q, $request))
            ->tap(fn (Builder $q) => $this->applyExcludeOwned($q, $request, 'package_id'))
            ->with('packageType')
            ->withCount('items')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(20);

        return PackageResource::collection($packages);
    }

    /**
     * GET /package-types — the tenant's content-package types (B27), for the
     * student-facing package filter. Public + tenant-scoped; unpaginated (the set
     * is small) so the client can render the full chip row in one call.
     */
    public function packageTypes(): AnonymousResourceCollection
    {
        $types = PackageType::query()->orderBy('sort_order')->orderBy('id')->get();

        return PackageTypeResource::collection($types);
    }

    /**
     * GET /packages/{package:uuid} — a single purchasable package with its
     * ordered items (lessons + sub-packages) for the public package-detail page.
     * Only purchasable packages are exposed publicly; anything else is a 404.
     */
    public function showPackage(Package $package): PackageResource
    {
        abort_unless((bool) $package->is_purchasable, 404);

        $package->load(['packageType', 'items' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])
            ->loadCount('items');

        return new PackageResource($package);
    }

    /**
     * Channel filter on the `access_mode` column. Reuses {@see AccessMode::isVisibleTo}
     * so `both` content always shows and a `both` mode returns every channel — i.e.
     * the filter behaves like a student of that study_mode browsing.
     *
     * A logged-in single-channel student (center/online) is HARD-scoped to their own
     * channel: their study_mode wins and any `?access_mode=` override is ignored, so a
     * center student can never browse online content (VD §7). Hybrid (`both`) and
     * anonymous callers fall back to the explicit `?access_mode=` query filter.
     */
    private function applyAccessMode(Builder $query, Request $request): void
    {
        $mode = $this->effectiveAccessMode($request);

        if ($mode === null) {
            return;
        }

        $allowed = array_map(
            fn (AccessMode $m) => $m->value,
            array_filter(AccessMode::cases(), fn (AccessMode $m) => $m->isVisibleTo($mode)),
        );

        $query->whereIn('access_mode', $allowed);
    }

    /**
     * The channel to scope the catalogue by: the authenticated student's own
     * study_mode when they are a single-channel student, otherwise the explicit
     * `?access_mode=` request filter. Auth is optional on this public route, so the
     * user is resolved via the sanctum guard (mirrors {@see applyExcludeOwned}).
     */
    private function effectiveAccessMode(Request $request): ?AccessMode
    {
        $user = $request->user() ?? auth('sanctum')->user();

        if ($user !== null) {
            $studyMode = $this->studyMode->studyModeFor(
                (int) $this->context->tenantOrFail()->getKey(),
                (int) $user->getKey(),
            );
            if ($studyMode !== AccessMode::Both) {
                return $studyMode;
            }
        }

        return AccessMode::tryFrom((string) $request->string('access_mode'));
    }

    /**
     * Optional ?exclude_owned=1 — drop items the calling student already owns, so
     * the "Explore" surface (VD F13/Item) shows only not-yet-bought content. Auth
     * is optional on this public route, so the user is resolved via the sanctum
     * guard from the bearer token; anonymous callers get the full catalogue.
     * `$column` is the enrollment provenance key: `lesson_id` or `package_id`.
     */
    private function applyExcludeOwned(Builder $query, Request $request, string $column): void
    {
        if (! $request->boolean('exclude_owned')) {
            return;
        }

        $user = $request->user() ?? auth('sanctum')->user();
        if ($user === null) {
            return;
        }

        $ownedIds = Enrollment::query()
            ->where('user_id', $user->getKey())
            ->grantsAccess()
            ->whereNotNull($column)
            ->pluck($column)
            ->unique()
            ->all();

        if ($ownedIds !== []) {
            $query->whereNotIn('id', $ownedIds);
        }
    }

    /**
     * Optional ?academic_year=<uuid> narrowing for the year-scoped lessons/packages
     * views. The uuid is tenant-scoped by the BelongsToTenant global scope; an
     * unknown/foreign uuid resolves to no year, yielding an empty result set rather
     * than silently leaking every year.
     */
    private function applyAcademicYear(Builder $query, Request $request): void
    {
        if (! $request->filled('academic_year')) {
            return;
        }

        $yearId = AcademicYear::query()
            ->where('uuid', (string) $request->string('academic_year'))
            ->value('id');

        $query->where('academic_year_id', $yearId ?? 0);
    }
}
