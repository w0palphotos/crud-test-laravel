<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use OpenApi\Attributes as OA;

#[OA\Info(
    title: 'Product Catalogue API',
    version: '1.0.0',
    description: 'A Sanctum-authenticated CRUD API. Issue a token at POST /api/auth/token, then paste it into Swagger UI\'s Authorize dialog.',
)]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'Sanctum personal access token',
    description: 'Paste the token returned by POST /api/auth/token.',
)]
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The assistant calls a metered external API on every request, so it gets
        // its own limit keyed on the account rather than the address. The built-in
        // throttle keys on the address for guests, and deployed behind Vercel
        // every visitor shares the proxy's IP, which would collapse the whole
        // application into one bucket.
        RateLimiter::for('assistant', fn (Request $request) => [
            Limit::perMinute(20)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())),
        ]);
    }
}
