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
        // Deployed behind Vercel's load balancer, so X-Forwarded-For is the only
        // way to learn the real client IP. Without this, every visitor shares the
        // proxy's IP and any throttle collapses into one global bucket.
        $middleware->trustProxies(at: '*');

        // The web guard sends guests to the login form and signed-in users to the
        // product list, rather than to the framework defaults of / and back.
        //
        // The api group is unaffected: it authenticates with auth:sanctum and its
        // exceptions render as JSON, so a guest calling the API still gets a 401
        // body instead of a redirect to an HTML page.
        $middleware->redirectGuestsTo(fn (Request $request) => route('login'));
        $middleware->redirectUsersTo(fn (Request $request) => route('products.index'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
