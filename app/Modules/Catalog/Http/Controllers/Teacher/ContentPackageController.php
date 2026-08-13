<?php

namespace App\Modules\Catalog\Http\Controllers\Teacher;

use App\Modules\Catalog\Enums\AccessMode;
use App\Modules\Catalog\Http\Requests\PackageRequest;
use App\Modules\Catalog\Http\Resources\PackageItemResource;
use App\Modules\Catalog\Http\Resources\PackageResource;
use App\Modules\Catalog\Models\Package;
use App\Modules\Catalog\Models\PackageItem;
use App\Modules\Catalog\Services\PackageItemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * /teacher/content-packages (VD change set §8.4, doc 13 Phase 5) — recursive
 * content packages: the single grouping that replaces course/unit/bundle. Base
 * path is `content-packages` (NOT `packages` — that belongs to Billing
 * subscription plans, D13-1). Year-scoped by the `academic-year` middleware +
 * the BelongsToAcademicYear scope, so a package from another year (or tenant) is
 * simply not found → 404. Bound by id (like standalone lessons).
 */
class ContentPackageController
{
    public function __construct(private readonly PackageItemService $items) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $packages = Package::query()
            ->with('packageType')
            ->withCount('items')
            ->when(
                $request->filled('access_mode'),
                fn ($q) => $q->where('access_mode', $request->string('access_mode')),
            )
            ->when(
                $request->filled('search'),
                fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'),
            )
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(20);

        return PackageResource::collection($packages);
    }

    public function store(PackageRequest $request): JsonResponse
    {
        // tenant_id + academic_year_id are auto-filled by the model traits.
        $package = Package::create($request->validated());

        return (new PackageResource($package->fresh()->load('items', 'packageType')))
            ->response()->setStatusCode(201);
    }

    public function show(Package $package): PackageResource
    {
        return new PackageResource($package->load('items', 'packageType'));
    }

    public function update(PackageRequest $request, Package $package): PackageResource
    {
        $data = $request->validated();

        // Narrowing the package's channel must not orphan a wider existing child.
        if (array_key_exists('access_mode', $data)) {
            $this->items->assertNarrowingAllowed($package, AccessMode::from($data['access_mode']));
        }

        $package->update($data);

        return new PackageResource($package->load('items', 'packageType'));
    }

    public function destroy(Package $package): Response
    {
        // Detaches its items via the package_id FK cascade (and the Package
        // `deleting` hook detaches it from any parent). Member lessons are
        // reusable and are never deleted here.
        $package->delete();

        return response()->noContent();
    }

    public function storeItem(Request $request, Package $package): JsonResponse
    {
        $validated = $request->validate([
            'item_type' => ['required', Rule::in([PackageItem::TYPE_LESSON, PackageItem::TYPE_PACKAGE])],
            'item_id' => ['required', 'integer'],
        ]);

        $item = $this->items->attach($package, $validated['item_type'], (int) $validated['item_id']);

        return (new PackageItemResource($item))->response()->setStatusCode(201);
    }

    public function destroyItem(Package $package, PackageItem $item): Response
    {
        $this->items->detach($package, $item);

        return response()->noContent();
    }

    public function reorderItems(Request $request, Package $package): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['integer'],
        ]);

        $this->items->reorder($package, $validated['order']);

        return PackageItemResource::collection($package->items()->get());
    }
}
