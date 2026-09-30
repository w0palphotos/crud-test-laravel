<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DocumentationApiTest extends TestCase
{
    public function test_the_spec_route_serves_the_committed_specification(): void
    {
        $response = $this->getJson('/docs')->assertOk();

        $this->assertSame(
            file_get_contents(storage_path('api-docs/api-docs.json')),
            $response->getContent(),
            'The route must serve the committed file verbatim, not regenerate it.',
        );
    }

    /**
     * The spec route used to carry throttle:60,1, which is backed by CACHE_STORE
     * and therefore by the database. Every request paid two round trips through a
     * transaction pooler before the controller ran, measured at 6.4s for a 27KB
     * file, and an unreachable database took the documentation offline entirely.
     *
     * Asserting the route still answers is not enough to catch that, because the
     * suite pins CACHE_STORE=array, so a cache-backed limiter passes here happily
     * while costing real round trips in production. This reads the registered
     * middleware instead, which is what actually ships.
     */
    public function test_the_spec_route_carries_no_cache_backed_middleware(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route) => $route->uri() === 'docs');

        $this->assertNotNull($route, 'The docs route must be registered.');

        $middleware = $route->gatherMiddleware();

        $this->assertNotContains(
            'throttle:60,1',
            $middleware,
            'A cache-backed limiter on /docs makes the public documentation depend on database availability and adds a round trip per request.',
        );

        $this->assertSame(
            [],
            array_values(array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'throttle'))),
            'No throttle of any kind should sit in front of a static spec file.',
        );
    }

    public function test_the_spec_is_a_committed_file_rather_than_generated_per_request(): void
    {
        $this->assertFileExists(
            storage_path('api-docs/api-docs.json'),
            'The spec must be committed, or the route 404s on a host that cannot regenerate it.',
        );
    }

    public function test_the_documentation_page_is_served(): void
    {
        $this->get('/api/documentation')->assertOk();
    }
}
