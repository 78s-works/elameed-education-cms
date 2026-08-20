<?php

namespace App\Modules\Centers\Http\Controllers;

use App\Modules\Centers\Models\Center;
use Illuminate\Http\JsonResponse;

/**
 * GET /centers — the resolved tenant's ACTIVE physical centers, public.
 *
 * The registration form needs this: a center student picks their branch from a
 * list. Before this endpoint the SPA had to make the student type the center's
 * raw uuid (validated by RegisterRequest), which no student could ever know, so
 * the manual on-site path was effectively unusable — only the Center ID-code
 * path worked. Tenant-scoped by the BelongsToTenant global scope; exposes only
 * the public branch identity (uuid + name + address), never phone/internal ids.
 */
class PublicCenterController
{
    public function __invoke(): JsonResponse
    {
        $centers = Center::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['uuid', 'name', 'address'])
            ->map(fn (Center $c) => [
                'uuid' => $c->uuid,
                'name' => $c->name,
                'address' => $c->address,
            ])
            ->all();

        return response()->json(['data' => $centers]);
    }
}
