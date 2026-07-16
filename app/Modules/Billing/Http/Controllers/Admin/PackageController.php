<?php

namespace App\Modules\Billing\Http\Controllers\Admin;

use App\Modules\Billing\Http\Requests\StorePackageRequest;
use App\Modules\Billing\Http\Requests\UpdatePackageRequest;
use App\Modules\Billing\Http\Resources\PackageResource;
use App\Modules\Billing\Models\SubscriptionPackage;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * /admin/packages (M03, FR-M03-01/02) — platform-defined teacher subscription
 * plans. Central host + platform admin only (see the /admin/* group in
 * routes/api.php). Not tenant-scoped.
 */
class PackageController
{
    public function index(): AnonymousResourceCollection
    {
        return PackageResource::collection(
            SubscriptionPackage::query()->orderBy('sort_order')->orderBy('id')->get()
        );
    }

    public function store(StorePackageRequest $request): JsonResponse
    {
        $package = SubscriptionPackage::create($this->payload($request->validated()));

        app(AuditLogger::class)->log('package.created', ['package' => $package->slug], null, 'subscription_package', $package->id);

        return (new PackageResource($package))->response()->setStatusCode(201);
    }

    public function show(SubscriptionPackage $package): PackageResource
    {
        return new PackageResource($package);
    }

    public function update(UpdatePackageRequest $request, SubscriptionPackage $package): PackageResource
    {
        $package->update($this->payload($request->validated()));

        app(AuditLogger::class)->log('package.updated', ['package' => $package->slug, 'changes' => $request->validated()], null, 'subscription_package', $package->id);

        return new PackageResource($package->refresh());
    }

    public function destroy(SubscriptionPackage $package): Response
    {
        // Soft-delete = retire: it disappears from the catalogue but existing
        // tenant_subscriptions keep their package_id reference intact.
        $package->delete();

        app(AuditLogger::class)->log('package.retired', ['package' => $package->slug], null, 'subscription_package', $package->id);

        return response()->noContent();
    }

    /**
     * Keep only canonical limit keys so arbitrary keys can't be persisted.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payload(array $data): array
    {
        if (array_key_exists('limits', $data) && is_array($data['limits'])) {
            $data['limits'] = array_intersect_key(
                $data['limits'],
                array_flip(SubscriptionPackage::LIMIT_KEYS),
            );
        }

        return $data;
    }
}
