<?php

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
            'admin'       => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'device.auth' => \App\Http\Middleware\ValidateDeviceToken::class,
        ]);

        // Behind nginx/a load balancer, trust the standard forwarded headers so
        // Request::ip() and URL::forceScheme('https') see the real client and scheme
        // instead of the proxy's. TRUSTED_PROXIES=* trusts any proxy (fine when the
        // app itself is not directly internet-reachable, e.g. behind a cloud LB);
        // set it to a comma-separated CIDR list otherwise.
        $trustedProxies = trim(env('TRUSTED_PROXIES', ''));
        $middleware->trustProxies(
            at: match (true) {
                $trustedProxies === '*' => '*',
                $trustedProxies === ''  => null,
                default                 => array_map('trim', explode(',', $trustedProxies)),
            },
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // api/* always gets JSON (hardware/device callers may not send an Accept
        // header); everything else falls back to Laravel's normal content
        // negotiation so fetch()-driven web UI (e.g. the enrollment wizard) still
        // gets JSON back for a thrown ValidationException instead of being
        // redirected to the HTML page it posted from.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
