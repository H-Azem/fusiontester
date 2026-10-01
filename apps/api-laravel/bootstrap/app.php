<?php

use App\Http\Middleware\EnforceTrustedOrigin;
use App\Http\Middleware\RequireSession;
use App\Http\Middleware\RequireSettingsUnlock;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        // The dashboard proxies /api/* straight onto this service's root, so the
        // routes have to live at the root rather than under /api.
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The origin guard runs on every request, matching the behaviour the
        // dashboard was built against, rather than being attached per route.
        $middleware->append(EnforceTrustedOrigin::class);

        $middleware->alias([
            'auth.session' => RequireSession::class,
            'settings.unlocked' => RequireSettingsUnlock::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->expectsJson() || ! $request->is('up'),
        );
    })->create();
