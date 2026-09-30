<?php

namespace Tests\Feature;

use Tests\TestCase;

class WelcomePageTest extends TestCase
{
    /**
     * The welcome page is the only route in the web middleware group, and it used
     * to fail in production with a missing "sessions" relation: StartSession ran
     * for a route that never uses a session, against a table this project has no
     * migration for.
     *
     * The suite sets SESSION_DRIVER=array in phpunit.xml, so the default
     * configuration cannot see that failure. Forcing the deployed driver here is
     * what makes the regression visible.
     */
    public function test_it_renders_without_a_sessions_table(): void
    {
        config(['session.driver' => 'database']);

        $this->get('/')->assertOk();
    }

    public function test_the_api_does_not_use_sessions(): void
    {
        config(['session.driver' => 'database']);

        $this->getJson('/api/products')->assertUnauthorized();
    }
}
