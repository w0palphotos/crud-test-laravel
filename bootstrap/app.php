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
        // This application exposes no web login page, so the auth middleware must
        // not try to redirect guests; unauthenticated API calls answer 401 JSON.
        $middleware->redirectGuestsTo(null);

        // Deployed behind Vercel's load balancer, so X-Forwarded-For is the only
        // way to learn the real client IP. Without this, every visitor shares the
        // proxy's IP and any throttle collapses into one global bucket.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
