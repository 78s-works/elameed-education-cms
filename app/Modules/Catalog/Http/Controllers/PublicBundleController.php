<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Http\Resources\BundleResource;
use App\Modules\Catalog\Models\Bundle;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Public storefront for packages (GET /bundles, /bundles/{slug}) of the resolved
 * tenant. Only published + purchasable packages are listed; tenant isolation is
 * via the BelongsToTenant scope. No auth.
 */
class PublicBundleController
{
    public function index(): AnonymousResourceCollection
    {
        $bundles = Bundle::query()
            ->published()
            ->where('purchase_enabled', true)
            ->withCount('items')
            ->latest()
            ->paginate(20);

        return BundleResource::collection($bundles);
    }

    public function show(Bundle $bundle): BundleResource
    {
        // Route binding scopes to the tenant; hidden/scheduled packages 404 publicly.
        abort_unless($bundle->isPublished(), 404);

        return new BundleResource($bundle->load('items.course', 'items.unit'));
    }
}
