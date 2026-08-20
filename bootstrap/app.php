<?php

use App\Http\Middleware\ResolveAcademicYear;
use App\Modules\Identity\Http\Middleware\AuthenticateWithTokenCookie;
use App\Modules\Identity\Http\Middleware\EnsureActiveMembership;
use App\Modules\Identity\Http\Middleware\EnsurePermission;
use App\Modules\Identity\Http\Middleware\EnsureTenantRole;
use App\Modules\Identity\Http\Middleware\VerifyCookieCsrf;
use App\Modules\PlatformAdmin\Http\Middleware\EnsureCentralHost;
use App\Modules\PlatformAdmin\Http\Middleware\EnsurePlatformAdmin;
use App\Modules\Tenancy\Http\Middleware\DynamicTenantCors;
use App\Modules\Tenancy\Http\Middleware\EnsureRegisteredDomain;
use App\Modules\Tenancy\Http\Middleware\ResolveTenant;
use App\Support\Http\ApiExceptionRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Runs before Laravel's HandleCors so a registered tenant origin (custom
        // domain or subdomain) is trusted for CORS, not just the static list.
        $middleware->prepend(DynamicTenantCors::class);

        // Bridge the httpOnly auth cookie into a Bearer header, then enforce the
        // double-submit CSRF token for cookie-authenticated mutations. Appended to
        // the `api` group so both run before any route-level `auth:sanctum`.
        // Bearer-header clients and Sanctum::actingAs() tests are unaffected.
        $middleware->appendToGroup('api', [
            AuthenticateWithTokenCookie::class,
            VerifyCookieCsrf::class,
        ]);

        // The middleware priority list hoists `Authenticate` (AuthenticatesRequests)
        // ahead of SubstituteBindings — and thus ahead of the api-group bridge above
        // — so `auth:sanctum` would 401 a cookie request before the cookie is read.
        // Pin the bridge to run BEFORE Authenticate so the Bearer header is in place.
        $middleware->prependToPriorityList(
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            AuthenticateWithTokenCookie::class,
        );

        $middleware->alias([
            'role' => EnsureTenantRole::class,
            'admin' => EnsurePlatformAdmin::class,
            'active' => EnsureActiveMembership::class,
            // Granular assistant permission gate (M18) — used inside
            // role:teacher,assistant groups; teachers pass implicitly.
            'permission' => EnsurePermission::class,
            // Pins the platform-admin console to a central/admin host — /admin/*
            // must never answer on a teacher academy's domain.
            'central' => EnsureCentralHost::class,
            // Resolves the X-Academic-Year header into AcademicYearContext so
            // content queries scope to a year. Mount on tenant-scoped routes only.
            'academic-year' => ResolveAcademicYear::class,
        ]);

        // `tenant` is a GROUP, not an alias: the domain gate runs first (rejects
        // hosts not registered to an active tenant) and only then does the
        // resolver bind the tenant + RLS session. As a group it cannot be opted
        // out of — every tenant-scoped route gets the gate ahead of it.
        $middleware->group('tenant', [
            EnsureRegisteredDomain::class,
            ResolveTenant::class,
        ]);

        // API is stateless/JSON-only and has no `login` route. Without this, an
        // unauthenticated api/* request whose client omits `Accept: application/json`
        // makes Authenticate::redirectTo() eagerly resolve route('login') and throw
        // RouteNotFoundException (a 500) before the 401 can be rendered. Returning
        // null keeps the AuthenticationException intact → clean 401 envelope.
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') ? null : '/',
        );

        // Resolve the tenant (and bind the RLS session) BEFORE route-model
        // binding runs — otherwise a bound tenant-scoped model is fetched with
        // no tenant scope and could cross tenants. Isolation test guards this.
        // The domain gate runs before the resolver so an unknown/suspended host
        // is rejected before any tenant work happens.
        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            ResolveTenant::class,
        );
        $middleware->prependToPriorityList(
            ResolveTenant::class,
            EnsureRegisteredDomain::class,
        );

        // Same reasoning for the academic year: resolve X-Academic-Year (after the
        // tenant, since the year lookup is tenant-scoped) BEFORE route-model
        // binding, so a {lesson} from another year is filtered out → 404. The
        // year-isolation test guards this.
        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            ResolveAcademicYear::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // All API/JSON errors use the { error: { code, message, details } } envelope.
        $exceptions->render(
            fn (Throwable $e, Request $request) => ApiExceptionRenderer::render($e, $request),
        );
    })->create();
