<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class DocumentationApiTest extends TestCase
{
    public function test_the_spec_route_is_rate_limited(): void
    {
        foreach (range(1, 60) as $ignored) {
            $this->getJson('/docs')->assertOk();
        }

        $this->getJson('/docs')->assertStatus(429);
    }

    public function test_the_documentation_page_is_not_rate_limited(): void
    {
        foreach (range(1, 61) as $ignored) {
            $this->get('/api/documentation')->assertOk();
        }
    }
}
