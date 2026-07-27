<?php

namespace App\Modules\Commerce\Http\Controllers\Teacher;

use App\Modules\Catalog\Models\Course;
use App\Modules\Commerce\Http\Requests\CouponRequest;
use App\Modules\Commerce\Http\Resources\CouponResource;
use App\Modules\Commerce\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * /teacher/coupons (M21). Tenant-scoped via BelongsToTenant + {coupon:uuid}
 * binding (a cross-tenant uuid 404s). The `course` scope is addressed by course
 * uuid on the wire and resolved to the internal id here.
 */
class CouponController
{
    public function index(): AnonymousResourceCollection
    {
        return CouponResource::collection(
            Coupon::query()->with('course')->latest()->paginate(20)
        );
    }

    public function store(CouponRequest $request): JsonResponse
    {
        $coupon = new Coupon($request->safe()->except('course'));
        $coupon->course_id = $this->resolveCourseId($request);
        $coupon->save(); // BelongsToTenant fills tenant_id

        return (new CouponResource($coupon->load('course')))->response()->setStatusCode(201);
    }

    public function show(Coupon $coupon): CouponResource
    {
        return new CouponResource($coupon->load('course'));
    }

    public function update(CouponRequest $request, Coupon $coupon): CouponResource
    {
        $coupon->fill($request->safe()->except('course'));

        if ($request->has('course')) {
            $coupon->course_id = $this->resolveCourseId($request);
        }

        $coupon->save();

        return new CouponResource($coupon->load('course'));
    }

    public function destroy(Coupon $coupon): Response
    {
        $coupon->delete(); // soft delete

        return response()->noContent();
    }

    /** Map the request's course uuid to a tenant-scoped course id (null = cart-wide). */
    private function resolveCourseId(CouponRequest $request): ?int
    {
        $uuid = $request->validated('course');

        return $uuid ? Course::query()->where('uuid', $uuid)->value('id') : null;
    }
}
