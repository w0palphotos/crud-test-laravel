<?php

use App\Http\Middleware\CacheInertiaResponses;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureCanWrite;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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

        // The web guard sends guests to the login form and signed-in users to
        // the dashboard, rather than to the framework defaults of / and back.
        //
        // The landing page depends on the account. An ordinary user can now open
        // the dashboard, but their landing page is still the account page: it is
        // the one page that says what this account is, rather than what the
        // business sold, and it carries the controls that act on the caller alone.
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
            CacheInertiaResponses::class,
        ]);

        // Sales (non-admin) boleh membuat dan menghapus data milik sendiri;
        // edit/update tetap jadi wilayah admin. Daftarkan sebagai alias agar
        // bisa dipakai per-route di routes/web.php.
        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'can.write' => EnsureCanWrite::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A POST that exceeds PHP's post_max_size never reaches the controller or
        // the form request, so it can never become a normal validation error.
        // Catch it here and answer the Inertia uploader with a 422 that carries
        // the error under the document column instead of a 413 error page.
        $exceptions->render(function (PostTooLargeException $e, Request $request): ?Response {
            if ($request->header('X-Inertia')) {
                return response()->json([
                    'errors' => ['file' => ['Berkas terlalu besar. Unggah berkas yang lebih kecil.']],
                ], 422, ['X-Inertia' => 'true']);
            }

            return null;
        });
    })->create();
