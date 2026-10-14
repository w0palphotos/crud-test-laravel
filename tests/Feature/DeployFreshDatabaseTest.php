<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * A fresh database must build and seed on the engine production uses.
 *
 * This is a smoke test for the deploy path, not a restatement of the migration
 * tests. Every other test in this suite runs against a schema that either was
 * already migrated or was migrated by a harness, so a migration that only works
 * on SQLite passes there and then fails at deploy time with a syntax error from
 * a Postgres-only statement, a driver guard that fires on the wrong engine, or a
 * seeder that violates a constraint SQLite does not enforce.
 *
 * None of those are reachable by asserting on the migrated schema, because the
 * schema is the same either way. The thing that differs is the run, so this test
 * performs the run and then asks three questions about the result.
 *
 * Three questions in one test rather than three tests, because they share a setup
 * that costs a full migrate-and-seed. Split, a broken migration fails all three
 * and reports the same defect three times; together, the first failing assertion
 * stops the test and the message says what actually went wrong.
 *
 * DatabaseMigrations rather than RefreshDatabase, for the reason
 * RowLevelSecurityTest gives: a wrapping transaction is invisible to a second
 * connection and does not play well with the work migrate:fresh does. It also
 * leaves the database clean, so a shared test database is not left seeded for
 * whatever runs next.
 *
 * Needs a Postgres to point at:
 *
 *     DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5499 DB_DATABASE=rlscheck \
 *     DB_USERNAME=postgres DB_PASSWORD= DB_URL= \
 *     APP_ENV=testing CACHE_STORE=array SESSION_DRIVER=array \
 *     php artisan test --group=deploy
 *
 * It skips on SQLite rather than failing, because the SQLite suite is not the
 * deploy target, and a machine without a Postgres should still be able to run the
 * rest of the gate.
 */
#[Group('deploy')]
class DeployFreshDatabaseTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * The tables enable_row_level_security.php claims to cover.
     *
     * Duplicated from that migration on purpose: asserting against the migration
     * would make the test pass whenever the migration shrinks its own list, which
     * is exactly the change a deploy gate should refuse to accept quietly.
     *
     * @var list<string>
     */
    private const TABLES = [
        'cities',
        'salespeople',
        'categories',
        'suppliers',
        'tags',
        'customers',
        'customer_profiles',
        'product_images',
        'category_product',
        'product_supplier',
        'sales',
        'sale_items',
        'sale_status_events',
        'payments',
        'sale_tag',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped(
                'Deployment runs on Postgres. Run the gate with DB_CONNECTION=pgsql — see the class docblock.'
            );
        }
    }

    public function test_a_fresh_migrate_and_seed_produces_a_usable_database(): void
    {
        $exit = Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);

        $this->assertSame(
            0,
            $exit,
            'migrate:fresh --seed failed on Postgres: '.trim(Artisan::output())
        );

        $missing = array_values(array_filter(
            self::TABLES,
            fn (string $table): bool => ! Schema::hasTable($table),
        ));

        $this->assertSame(
            [],
            $missing,
            'These tables are missing after migrate:fresh. enable_row_level_security.php skips any table '
            .'it cannot see, so a table that never got created loses its RLS policy without an error.'
        );

        $empty = array_values(array_filter(
            ['sales', 'sale_items', 'customers', 'products'],
            fn (string $table): bool => DB::table($table)->count() === 0,
        ));

        $this->assertSame(
            [],
            $empty,
            'These tables are empty after seeding, so the deployed dashboard opens onto nothing. '
            .'Check whether DatabaseSeeder still calls SalesDeskSeeder.'
        );
    }
}
