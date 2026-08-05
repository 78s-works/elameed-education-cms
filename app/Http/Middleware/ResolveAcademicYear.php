<?php

namespace App\Http\Middleware;

use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Catalog\Services\AcademicYearContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the request's academic year from the `X-Academic-Year` header and
 * stores it in AcademicYearContext, so BelongsToAcademicYear scopes content to it.
 *
 * Mount only on routes already inside the `tenant` group — the AcademicYear lookup
 * relies on the BelongsToTenant global scope to reject another tenant's uuid.
 *
 *   - 422 when the header is absent (the client must always declare a year).
 *   - 403 when the uuid is unknown or belongs to another tenant.
 */
class ResolveAcademicYear
{
    public function __construct(
        private readonly AcademicYearContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $uuid = $request->header('X-Academic-Year');

        if (! is_string($uuid) || $uuid === '') {
            throw ValidationException::withMessages([
                'academic_year' => 'The X-Academic-Year header is required.',
            ]);
        }

        // Tenant-scoped by the BelongsToTenant global scope: a uuid belonging to
        // another tenant simply isn't found → treated as forbidden.
        $year = AcademicYear::query()->where('uuid', $uuid)->first();

        if ($year === null) {
            throw new AuthorizationException('Academic year not found or not accessible.');
        }

        $this->context->set((int) $year->getKey());

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->context->forget();
    }
}
