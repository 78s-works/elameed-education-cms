<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Services\LandingResolver;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * GET /tenant/landing — 🌐 Public. Fully-resolved landing for the SPA
 * (LANDING_CONTRACT_V2.md): layout + nav + sections, with dynamic sections
 * resolved to real items. No auth required; if a bearer token IS present,
 * resolved course items carry `enrolled` for that student.
 *
 * This is a public hot path (hit on every visitor's first paint), so the
 * viewer-agnostic base payload is cached per tenant. The cache key carries the
 * profile's updated_at, so a landing edit is reflected instantly (new key),
 * while course/review changes surface within the (short) TTL. The per-student
 * `enrolled` overlay is applied AFTER the cache read, so cached data is never
 * user-specific. See config/tenancy.php ('landing_cache_*').
 */
class TenantLandingController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly LandingResolver $resolver,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $tenant = $this->context->tenantOrFail();
        $profile = $tenant->teacherProfile;

        $version = $profile?->updated_at?->getTimestamp() ?? 0;
        $key = 'tenant_landing:'.$tenant->getKey().':'.$version;
        $ttl = (int) config('tenancy.landing_cache_ttl', 60);

        $base = Cache::store(config('tenancy.cache.store'))
            ->remember($key, $ttl, fn () => $this->resolver->resolve($tenant->getKey(), $profile));

        // Optional auth → overlay this student's active enrollments onto the
        // shared (cached) payload; anonymous visitors keep `enrolled: false`.
        $viewer = $request->user('sanctum');
        $data = $viewer
            ? $this->resolver->applyEnrollment($base, $tenant->getKey(), $viewer)
            : $base;

        return response()->json(['data' => $data]);
    }
}
