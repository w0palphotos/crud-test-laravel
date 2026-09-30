<?php

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

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

        // This API is token-only. Sanctum issues bearer tokens, auth:sanctum
        // reads the token, and nothing anywhere reads or writes a session.
        //
        // The web group's defaults are removed because the only web route is the
        // welcome page, and its StartSession middleware queried a "sessions"
        // table this project never created. With SESSION_DRIVER=database that
        // fails with a missing-relation error, and with the array driver it
        // still buys nothing.
        //
        // PreventRequestForgery goes with them: it reads the session to seed its
        // CSRF token cookie, so leaving it in place after removing StartSession
        // fails with "Session store not set on request". It is safe to drop
        // because there is no form on the page to forge a request against, and
        // every state-changing endpoint is under the api group, which never had
        // CSRF protection anyway.
        $middleware->removeFromGroup('web', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            PreventRequestForgery::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
