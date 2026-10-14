<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
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

        // A count, not an exact number. This used to assert exactly ten, which
        // was true of one seeder and became a lie the moment the sales desk
        // started contributing a catalogue of its own. What the assertion is for
        // is "a demo has something to show", and any non-zero count satisfies it
        // without breaking every time a product is added to the fixture.
        $this->assertGreaterThan(0, Product::count());
        $this->assertGreaterThan(0, Sale::count());
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

        // A deployed demo still needs data to show, so the catalogue and the
        // ledger are seeded unconditionally and only the test user is
        // environment gated.
        $this->assertGreaterThan(0, Product::count());
        $this->assertGreaterThan(0, Sale::count());

        // The ledger is what the dashboard reads. A production seed that created
        // no sales would deploy an admin panel with nothing in it.
        $this->assertSame(
            7,
            Sale::where('reference', 'like', 'SLV-CANONICAL-%')->count(),
        );
    }
}
