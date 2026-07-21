<?php

namespace App\Modules\Catalog\Http\Controllers\Teacher;

use App\Modules\Catalog\Http\Requests\BundleRequest;
use App\Modules\Catalog\Http\Resources\BundleResource;
use App\Modules\Catalog\Models\Bundle;
use App\Modules\Catalog\Models\BundleItem;
use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * /teacher/bundles (M04) — teacher-authored **packages**. A package groups
 * courses and/or units; buying it enrolls the student in every item (see
 * FulfillOrderService + EnrollmentService::grantBundle). The teacher sees ALL
 * their bundles regardless of visibility; tenant isolation is via BelongsToTenant
 * + uuid binding (a cross-tenant uuid 404s).
 */
class BundleController
{
    public function index(): AnonymousResourceCollection
    {
        $bundles = Bundle::query()->withCount('items')->latest()->paginate(20);

        return BundleResource::collection($bundles);
    }

    public function store(BundleRequest $request): JsonResponse
    {
        $data = $request->validated();

        $bundle = new Bundle($data);
        $bundle->slug = Bundle::makeUniqueSlug($data['title']);
        $bundle->save(); // BelongsToTenant fills tenant_id

        $this->syncItems($bundle, $data['items'] ?? []);

        return (new BundleResource($bundle->load('items.course', 'items.unit', 'items.lesson')))
            ->response()->setStatusCode(201);
    }

    public function show(Bundle $bundle): BundleResource
    {
        return new BundleResource($bundle->load('items.course', 'items.unit', 'items.lesson'));
    }

    public function update(BundleRequest $request, Bundle $bundle): BundleResource
    {
        // Slug stays stable across updates so public URLs don't break.
        $bundle->fill($request->validated())->save();

        if ($request->has('items')) {
            $this->syncItems($bundle, $request->validated('items'));
        }

        return new BundleResource($bundle->load('items.course', 'items.unit', 'items.lesson'));
    }

    public function destroy(Bundle $bundle): Response
    {
        // Soft delete — the package leaves the catalogue but enrollments already
        // granted from it keep working (their bundle_id link survives).
        $bundle->delete();

        return response()->noContent();
    }

    /**
     * Replace the package's items with the supplied set. Course items are resolved
     * by uuid, unit/lesson items by id; all were validated to belong to this tenant.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncItems(Bundle $bundle, array $items): void
    {
        $bundle->items()->delete();

        foreach (array_values($items) as $i => $item) {
            $row = ['sort_order' => $item['sort_order'] ?? $i];

            if ($item['type'] === BundleItem::TYPE_COURSE) {
                $course = Course::query()->where('uuid', $item['course'])->first();
                if ($course === null) {
                    continue;
                }
                $row += ['item_type' => BundleItem::TYPE_COURSE, 'course_id' => $course->getKey()];
            } elseif ($item['type'] === BundleItem::TYPE_UNIT) {
                $unit = Unit::query()->find($item['unit']);
                if ($unit === null) {
                    continue;
                }
                $row += ['item_type' => BundleItem::TYPE_UNIT, 'unit_id' => $unit->getKey()];
            } else {
                $lesson = Lesson::query()->find($item['lesson']);
                if ($lesson === null) {
                    continue;
                }
                $row += ['item_type' => BundleItem::TYPE_LESSON, 'lesson_id' => $lesson->getKey()];
            }

            $bundle->items()->create($row);
        }
    }
}
