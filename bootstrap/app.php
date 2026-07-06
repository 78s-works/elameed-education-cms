<?php

use App\Modules\Identity\Http\Middleware\EnsureTenantRole;
use App\Modules\PlatformAdmin\Http\Middleware\EnsurePlatformAdmin;
use App\Modules\Tenancy\Http\Middleware\ResolveTenant;
use App\Support\Http\ApiExceptionRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'role' => EnsureTenantRole::class,
            'admin' => EnsurePlatformAdmin::class,
        ]);

        // Resolve the tenant (and bind the RLS session) BEFORE route-model
        // binding runs — otherwise a bound tenant-scoped model is fetched with
        // no tenant scope and could cross tenants. Isolation test guards this.
        $middleware->prependToPriorityList(
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            ResolveTenant::class,
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
