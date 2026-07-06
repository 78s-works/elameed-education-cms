<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Services\TenantContext;
use App\Modules\Tenancy\Services\TenantResolver;
use App\Modules\Tenancy\Services\TenantSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the request's tenant and establishes the RLS session for it.
 *
 * Isolation-critical ordering: the GUC state is set at the START of every
 * request — bind() when a tenant resolves, reset() when none does — so a value
 * left on a reused (pooled/Octane/worker) connection can never be inherited by
 * a later request. terminate() resets again after the response is sent.
 * See 02_Architecture.md §4.2 and 06_Engineering_Guide.md §8.
 *
 * "Soft" resolver: an unresolved host is allowed through (platform/marketing
 * routes work). Routes that require a tenant enforce it themselves.
 */
class ResolveTenant
{
    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly TenantContext $context,
        private readonly TenantSession $session,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolver->resolve($request);

        if ($tenant !== null) {
            $this->context->setTenant($tenant);
            $this->session->bind((int) $tenant->getKey());
        } else {
            // No tenant → clear any GUC a reused connection might still hold.
            $this->session->reset();
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->session->reset();
        $this->context->forget();
    }
}
