<?php

namespace App\Modules\Commerce\Http\Controllers\Teacher;

use App\Modules\Commerce\Http\Requests\CouponRequest;
use App\Modules\Commerce\Http\Resources\CouponResource;
use App\Modules\Commerce\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * /teacher/coupons (M21). Tenant-scoped via BelongsToTenant + {coupon:uuid}
 * binding (a cross-tenant uuid 404s). The optional content scope is carried
 * directly as `target_type` (lesson|package) + numeric `target_id` (VD §7).
 */
class CouponController
{
    public function index(): AnonymousResourceCollection
    {
        return CouponResource::collection(
            Coupon::query()->latest()->paginate(20)
        );
    }

    public function store(CouponRequest $request): JsonResponse
    {
        $coupon = new Coupon($request->validated());
        $coupon->save(); // BelongsToTenant fills tenant_id

        return (new CouponResource($coupon))->response()->setStatusCode(201);
    }

    public function show(Coupon $coupon): CouponResource
    {
        return new CouponResource($coupon);
    }

    public function update(CouponRequest $request, Coupon $coupon): CouponResource
    {
        $coupon->fill($request->validated());
        $coupon->save();

        return new CouponResource($coupon);
    }

    public function destroy(Coupon $coupon): Response
    {
        $coupon->delete(); // soft delete

        return response()->noContent();
    }
}
