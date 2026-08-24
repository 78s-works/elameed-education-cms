<?php

namespace App\Http\Middleware;

use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Catalog\Services\AcademicYearContext;
use App\Support\Exceptions\DomainException;
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
        $studentProfile = $request->user()?->studentProfile;
        if ($studentProfile !== null) {
            // A student with NO academic year is an incomplete account: deny the
            // panel outright instead of falling through to header scoping (which
            // would leak every year's content). The academy must assign a year.
            if ($studentProfile->academic_year_id === null) {
                throw new DomainException(
                    'academic_year_required',
                    __('Your account has no academic year set. Please contact your academy.'),
                    403,
                );
            }

            $this->context->set((int) $studentProfile->academic_year_id);

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

        $this->assertYearIsInScope($request, (int) $year->getKey());

        $this->context->set((int) $year->getKey());

        return $next($request);
    }

    /**
     * Keep a year-scoped member inside their years (M20).
     *
     * Authority and scope are two different questions: a role says WHAT a member
     * may do, the years assigned to their membership say WHICH content they may
     * do it to. Without this, an assistant hired for the graduating year could
     * point the header at another year and act there with the same permissions.
     *
     * Deliberately kind-agnostic: a membership with NO assigned years is
     * unscoped (the academy owner), and one with years is confined to them. No
     * comparison against the membership kind, so authority keeps a single source.
     */
    private function assertYearIsInScope(Request $request, int $yearId): void
    {
        $user = $request->user();
        $tenant = app(\App\Modules\Tenancy\Services\TenantContext::class)->tenant();

        if ($user === null || $tenant === null) {
            return;
        }

        $membership = $user->membershipFor($tenant);

        if ($membership === null) {
            return;
        }

        $assigned = $membership->academicYears()->pluck('academic_years.id')->all();

        if ($assigned === [] || in_array($yearId, array_map('intval', $assigned), true)) {
            return;
        }

        throw new DomainException(
            'academic_year_out_of_scope',
            __('You are not assigned to this academic year.'),
            403,
        );
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->context->forget();
    }
}
