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
use App\Modules\Catalog\Services\PackageItemService;
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

    /**
     * GET /academic-years — the tenant's academic years (grades), for the public
     * registration grade picker. Tenant-scoped (BelongsToTenant); no auth, no
     * year context needed (this is where a student CHOOSES their year).
     */
    public function academicYears(): \Illuminate\Http\JsonResponse
    {
        $years = AcademicYear::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['uuid', 'name', 'sort_order'])
            ->map(fn (AcademicYear $y) => [
                'uuid' => $y->uuid,
                'name' => $y->name,
                'sort_order' => (int) $y->sort_order,
            ])
            ->all();

        return response()->json(['data' => $years]);
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
     * is small). Spec F4: only types that have PUBLISHED lessons show up — a type
     * qualifies when at least one of its packages has a published descendant
     * lesson (walking the recursive package_items).
     */
    public function packageTypes(Request $request, PackageItemService $items): AnonymousResourceCollection
    {
        $publishedLessonIds = Lesson::query()->published()->pluck('id')
            ->map(fn ($id) => (int) $id)->all();
        $publishedSet = array_flip($publishedLessonIds);

        $types = PackageType::query()
            // A logged-in student only sees their own year's types (server pin);
            // otherwise the optional ?academic_year narrowing applies.
            ->tap(fn (Builder $q) => $this->applyAcademicYear($q, $request))
            ->with(['packages:id,tenant_id,academic_year_id,package_type_id'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(function (PackageType $type) use ($items, $publishedSet): bool {
                foreach ($type->packages as $package) {
                    foreach ($items->descendantLessonIds($package) as $lessonId) {
                        if (isset($publishedSet[(int) $lessonId])) {
                            return true;
                        }
                    }
                }

                return false;
            })
            ->values();

        return PackageTypeResource::collection($types);
    }

    /**
     * GET /packages/{package:uuid} — a single package with its ordered items
     * (lessons + sub-packages) for the public package-detail modal + accordion.
     *
     * Not gated on `is_purchasable`: a buy-alone type's packages (and structural
     * sub-packages) are not sold as a whole yet must still be viewable so the
     * student can browse the tree and buy the individual lessons. The explore
     * grid ({@see packages()}) stays `is_purchasable`-gated, so hidden packages
     * never surface there — this route is reached only by uuid, from a listed
     * parent. Buying is separately gated at checkout, so a non-purchasable
     * package can be viewed but never bought as a whole. Route binding keeps it
     * tenant-scoped (foreign uuids 404).
     */
    public function showPackage(Package $package): PackageResource
    {
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
     * Academic-year scoping for the year-scoped lessons/packages views.
     *
     * A logged-in student is PINNED to their profile's academic year (grade),
     * server-authoritative: their catalogue only ever shows their own year and any
     * `?academic_year=` query is ignored (mirrors the study_mode channel pin and
     * the ResolveAcademicYear middleware, which can't reach this public route since
     * it isn't behind auth:sanctum). Anonymous/teacher callers fall back to the
     * optional `?academic_year=<uuid>` narrowing; an unknown/foreign uuid yields an
     * empty set rather than leaking every year.
     */
    private function applyAcademicYear(Builder $query, Request $request): void
    {
        $user = $request->user() ?? auth('sanctum')->user();
        $studentYearId = $user?->studentProfile?->academic_year_id;
        if ($studentYearId !== null) {
            $query->where('academic_year_id', $studentYearId);

            return;
        }

        if (! $request->filled('academic_year')) {
            return;
        }

        $yearId = AcademicYear::query()
            ->where('uuid', (string) $request->string('academic_year'))
            ->value('id');

        $query->where('academic_year_id', $yearId ?? 0);
    }
}
