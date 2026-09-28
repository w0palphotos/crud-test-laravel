<?php

namespace App\Providers;

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
        //
    }
}
