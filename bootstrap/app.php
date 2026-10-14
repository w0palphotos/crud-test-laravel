<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\HandleInertiaRequests;
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
        // dashboard, rather than to the framework defaults of / and back.
        //
        // The landing page depends on the account, because an ordinary user has
        // no dashboard to land on: sending them there would answer a successful
        // sign-in with a 403. The account page is the one page every signed-in
        // account can reach, so it is the honest default for them.
        //
        // The api group is unaffected: it authenticates with auth:sanctum and its
        // exceptions render as JSON, so a guest calling the API still gets a 401
        // body instead of a redirect to an HTML page.
        $middleware->redirectGuestsTo(fn (Request $request) => route('login'));
        $middleware->redirectUsersTo(fn (Request $request) => $request->user()?->is_admin
            ? route('dashboard')
            : route('account.show'));

        // The sales desk is administrator-only. Aliased rather than written out
        // per route so the rule is one declaration, applied to one group.
        $middleware->alias([
            'admin' => EnsureAdmin::class,
        ]);

        // Inertia resolves page components and shares props on the way past.
        // It goes at the end of the group so the session it reads is the one
        // StartSession has already established, not the guest session that
        // precedes it.
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
