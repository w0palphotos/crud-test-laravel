<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_test_user_outside_production(): void
    {
        (new DatabaseSeeder)->run();

        $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
        $this->assertDatabaseCount('products', 10);
    }

    public function test_it_does_not_seed_the_test_user_in_production(): void
    {
        // app()->environment() reads the 'env' container binding, so rebinding it
        // is what makes the seeder take its production branch.
        $this->app->instance('env', 'production');

        (new DatabaseSeeder)->run();

        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_it_still_seeds_products_in_production(): void
    {
        $this->app->instance('env', 'production');

        (new DatabaseSeeder)->run();

        // A deployed demo still needs data to show, so products are seeded
        // unconditionally and only the test user is environment gated.
        $this->assertDatabaseCount('products', 10);
    }
}
