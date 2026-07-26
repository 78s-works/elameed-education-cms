<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Http\Resources\LandingMetaResource;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/tenant/landing/meta — 🌐 Public. Returns the data the SPA needs to
 * render the landing's `<head>` + branding shell in one call: identity +
 * branding/theme + the teacher's key/value site metadata (SEO/OG tags), grouped
 * by `group`. This is the only public surface for the `teacher_meta` store.
 *
 * Like GET /tenant/context this is a public, rarely-changing hot path, so it
 * carries an ETag + short Cache-Control and answers a matching If-None-Match
 * with a bodyless 304. The ETag folds in both the branding version (profile
 * updated_at) AND the metadata version (latest updated_at + count), so any
 * branding or meta edit — including a delete — invalidates a cached response.
 */
class TenantLandingMetaController
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

        // Order the entries once here so the resource can group them as-is.
        $tenant = $context->tenant()->load([
            'teacherProfile',
            'metaEntries' => fn ($q) => $q->orderBy('group')->orderBy('sort_order')->orderBy('key'),
        ]);

        $meta = $tenant->metaEntries;
        $etag = $this->etag(
            $tenant->uuid,
            $tenant->status->value,
            $tenant->teacherProfile?->updated_at?->toIso8601String() ?? '',
            (string) $meta->count(),
            $meta->max('updated_at')?->toIso8601String() ?? '',
        );
        $maxAge = (int) config('tenancy.context_cache_ttl', 60);

        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return $this->withCaching(response()->noContent(304), $etag, $maxAge);
        }

        return $this->withCaching(
            (new LandingMetaResource($tenant))->response(),
            $etag,
            $maxAge,
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
