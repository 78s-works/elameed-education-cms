<?php

use App\Modules\Identity\Http\Middleware\EnsureActiveMembership;
use App\Modules\Identity\Http\Middleware\EnsureTenantRole;
use App\Modules\PlatformAdmin\Http\Middleware\EnsureCentralHost;
use App\Modules\PlatformAdmin\Http\Middleware\EnsurePlatformAdmin;
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
        $middleware->alias([
            'role' => EnsureTenantRole::class,
            'admin' => EnsurePlatformAdmin::class,
            'active' => EnsureActiveMembership::class,
            // Pins the platform-admin console to a central/admin host — /admin/*
            // must never answer on a teacher academy's domain.
            'central' => EnsureCentralHost::class,
        ]);

        // `tenant` is a GROUP, not an alias: the domain gate runs first (rejects
        // hosts not registered to an active tenant) and only then does the
        // resolver bind the tenant + RLS session. As a group it cannot be opted
        // out of — every tenant-scoped route gets the gate ahead of it.
        $middleware->group('tenant', [
            EnsureRegisteredDomain::class,
            ResolveTenant::class,
        ]);

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
