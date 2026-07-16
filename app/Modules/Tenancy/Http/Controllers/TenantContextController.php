<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Http\Resources\TenantContextResource;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/tenant/context — resolves the current host to a tenant and returns
 * its branding/theme/features for the SPA to boot. Public (no auth).
 * See 04_API_Specification.md → "Tenant context & branding".
 *
 * Public, cacheable, and rarely changing, so it carries an ETag (derived from
 * the tenant's identity/status + branding version) and a short Cache-Control.
 * A conditional request whose If-None-Match matches gets a bodyless 304.
 */
class TenantContextController
{
    public function __invoke(Request $request, TenantContext $context): Response
    {
        if (! $context->hasTenant()) {
            return response()->json([
                'error' => [
                    'code' => 'tenant_not_found',
                    'message' => 'لا يوجد حساب مرتبط بهذا العنوان.', // No academy configured for this address.
                ],
            ], 404);
        }

        $tenant = $context->tenant()->load('teacherProfile');
        $etag = $this->etag(
            $tenant->uuid,
            $tenant->status->value,
            $tenant->teacherProfile?->updated_at?->toIso8601String() ?? ''
        );
        $maxAge = (int) config('tenancy.context_cache_ttl', 60);

        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return $this->withCaching(response()->noContent(304), $etag, $maxAge);
        }

        return $this->withCaching(
            (new TenantContextResource($tenant))->response(),
            $etag,
            $maxAge
        );
    }

    private function etag(string ...$parts): string
    {
        return '"'.sha1(implode('|', $parts)).'"';
    }

    private function withCaching(Response $response, string $etag, int $maxAge): Response
    {
        return $response
            ->header('ETag', $etag)
            ->header('Cache-Control', "public, max-age={$maxAge}")
            // The dev X-Tenant override can pick a different tenant on the same
            // URL; Vary keeps a shared cache from serving the wrong academy.
            ->header('Vary', 'X-Tenant');
    }
}
