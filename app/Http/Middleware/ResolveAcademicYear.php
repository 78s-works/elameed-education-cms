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
 * Two modes (route param):
 *   - `academic-year` (default, strict): the header is mandatory — 422 if absent.
 *     Use on year-authoring surfaces where a new row MUST be stamped with a year.
 *   - `academic-year:optional`: resolve the header when present, otherwise fall
 *     through with no year context (the trait/global scope then no-op → the
 *     request stays tenant-only). Use site-wide so scoping engages the moment a
 *     client sends the header, without 422-breaking clients that don't yet.
 *
 * A present-but-unknown/foreign uuid is 403 in BOTH modes — a bad year is an
 * error, only an ABSENT header differs between the modes.
 */
class ResolveAcademicYear
{
    public function __construct(
        private readonly AcademicYearContext $context,
    ) {}

    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        // Server-authoritative student scoping: a student is pinned to the academic
        // year (grade) on their profile, set at registration. This OVERRIDES the
        // client header so a student can only ever see their own year's content —
        // teachers/assistants (no student profile) still drive scoping by header.
        $studentYearId = $request->user()?->studentProfile?->academic_year_id;
        if ($studentYearId !== null) {
            $this->context->set((int) $studentYearId);

            return $next($request);
        }

        $uuid = $request->header('X-Academic-Year');

        if (! is_string($uuid) || $uuid === '') {
            if ($mode === 'optional') {
                return $next($request);
            }

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
