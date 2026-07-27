<?php

namespace App\Modules\Tenancy\Http\Controllers\Teacher;

use App\Modules\Tenancy\Enums\TenantDomainType;
use App\Modules\Tenancy\Http\Requests\DomainRequest;
use App\Modules\Tenancy\Http\Resources\TenantDomainResource;
use App\Modules\Tenancy\Models\TenantDomain;
use App\Modules\Tenancy\Services\CustomDomainService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Teacher management of their academy's domains (M02, custom domains Part 2).
 * `tenant_domains` is global, so every action is explicitly scoped to the
 * resolved tenant — the auto-provisioned platform subdomain is read-only here;
 * teachers only add/remove their own custom domains.
 */
class DomainController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CustomDomainService $service,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $tenantId = $this->context->tenantOrFail()->getKey();

        $domains = TenantDomain::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get();

        return TenantDomainResource::collection($domains);
    }

    public function store(DomainRequest $request): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();

        $domain = $this->service->register(
            $tenantId,
            $request->validated('host'),
            $request->boolean('is_primary'),
        );

        app(AuditLogger::class)->log('domain.registered', [
            'host' => $domain->host,
        ], $tenantId, 'tenant_domain', $domain->getKey());

        return response()->json([
            'data' => array_merge(
                (new TenantDomainResource($domain))->resolve($request),
                ['dns' => $this->service->dnsInstructions($domain)],
            ),
        ], 201);
    }

    public function setPrimary(string $domain): TenantDomainResource
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $model = $this->ownedOrFail($tenantId, $domain);

        $this->service->makePrimary($model, $tenantId);

        return new TenantDomainResource($model->fresh());
    }

    public function destroy(string $domain): Response
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $model = $this->ownedOrFail($tenantId, $domain);

        // The platform subdomain is auto-managed and is the academy's fallback
        // host — it can't be removed here.
        abort_if($model->type === TenantDomainType::Subdomain, 422, 'The platform subdomain cannot be removed.');

        $this->service->remove($model);

        app(AuditLogger::class)->log('domain.removed', [
            'host' => $model->host,
        ], $tenantId, 'tenant_domain', $model->getKey());

        return response()->noContent();
    }

    /** Resolve a domain uuid to a row owned by the current tenant, or 404. */
    private function ownedOrFail(int $tenantId, string $uuid): TenantDomain
    {
        return TenantDomain::query()
            ->where('tenant_id', $tenantId)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }
}
