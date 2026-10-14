<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Row Level Security on the sales desk tables.
 *
 * Nothing here can be asserted on SQLite: RLS does not exist there, so a test
 * that passed on the default connection would be asserting that a statement the
 * driver would have rejected anyway did not run. Asserting the wrong engine is
 * how a Postgres-only behaviour reaches production untested.
 *
 * The behaviour is also invisible from Eloquent, because the application connects
 * as the table owner and the owner bypasses RLS. Proving it therefore means
 * connecting a second time, as a non-owner, and asking what that connection can
 * see.
 *
 * Two fixture traps shaped this file, and both are worth knowing about:
 *
 * **The test role needs table privileges, or the test proves nothing.** Postgres
 * checks privileges before it consults a policy, so a role with no grant is
 * refused at the permission layer and RLS is never reached. The denial is real,
 * but it is not evidence about the policy — and the whole file would stay green
 * with the migration switched off. Supabase grants anon and authenticated on the
 * public schema by default, which is exactly why RLS is the last line there.
 *
 * **Those grants cannot be set up outside the test.** `migrate:fresh` recreates
 * every table, so any GRANT applied beforehand is dropped by the first test that
 * runs — silently, leaving the later assertions asserting against a role that has
 * lost its privileges. This class therefore grants as the owner itself.
 *
 * That in turn rules out RefreshDatabase: its wrapping transaction is invisible
 * to a second connection, so a GRANT made inside it is one the non-owner role
 * cannot see. DatabaseMigrations migrates without wrapping, which is slower and
 * correct.
 *
 * Needs a Postgres to point at and a non-owner role to exist:
 *
 *     CREATE ROLE anon LOGIN PASSWORD 'anon';
 *     CREATE DATABASE rlscheck OWNER postgres;
 *
 *     DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5499 DB_DATABASE=rlscheck \
 *     DB_USERNAME=postgres DB_PASSWORD= DB_URL= \
 *     APP_ENV=testing CACHE_STORE=array SESSION_DRIVER=array \
 *     APP_TEST_ANON_DSN="pgsql:host=127.0.0.1;port=5499;dbname=rlscheck" \
 *     APP_TEST_ANON_USER=anon APP_TEST_ANON_PASSWORD=anon \
 *     php artisan test tests/Feature/RowLevelSecurityTest.php
 *
 * Without APP_TEST_ANON_DSN the role-dependent tests skip rather than fail,
 * because a missing fixture is a missing fixture and not a broken policy.
 *
 * @group pgsql
 */
class RowLevelSecurityTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * The tables the migration claims to cover.
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
            $this->markTestSkipped('RLS is Postgres-only. See the class docblock for how to run this.');
        }

        $this->grantTestRoleEverything();
    }

    /**
     * Give the non-owner role the same table privileges Supabase gives anon by
     * default, so RLS rather than a missing grant is what stops it.
     *
     * Committed, because a GRANT inside RefreshDatabase's wrapping transaction
     * would be invisible to the very connection this file is asking.
     */
    private function grantTestRoleEverything(): void
    {
        $role = $this->anonRole();

        if (! $this->roleExists($role)) {
            $this->markTestSkipped("Role {$role} does not exist. See the class docblock.");
        }

        DB::statement("GRANT USAGE ON SCHEMA public TO {$role}");
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO '.$role);
        DB::statement('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO '.$role);
    }

    private function anonRole(): string
    {
        return (string) env('APP_TEST_ANON_USER', 'anon');
    }

    private function roleExists(string $role): bool
    {
        return DB::selectOne(
            'SELECT count(*) AS found FROM pg_roles WHERE rolname = ?',
            [$role]
        )->found > 0;
    }

    /**
     * A second connection, as a role that is not the table owner.
     */
    private function nonOwnerConnection(): \PDO
    {
        $dsn = (string) env('APP_TEST_ANON_DSN', '');

        if ($dsn === '') {
            $this->markTestSkipped('APP_TEST_ANON_DSN is not set; there is no non-owner role to ask.');
        }

        $pdo = new \PDO(
            $dsn,
            $this->anonRole(),
            (string) env('APP_TEST_ANON_PASSWORD', ''),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );

        $pdo->exec('SET search_path TO public');

        return $pdo;
    }

    /**
     * The tables the database says have RLS switched on.
     *
     * @return list<string>
     */
    private function tablesWithRls(): array
    {
        return array_map(
            fn ($row): string => (string) $row->relname,
            DB::select(
                'SELECT c.relname FROM pg_class c
                 JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE c.relrowsecurity = true AND n.nspname = \'public\''
            )
        );
    }

    public function test_every_covered_table_has_row_level_security_enabled(): void
    {
        $enabled = $this->tablesWithRls();

        foreach (self::TABLES as $table) {
            $this->assertContains(
                $table,
                $enabled,
                "{$table} has no RLS enabled, so it is readable through PostgREST by anyone holding the anon key."
            );
        }
    }

    /**
     * The tables left out on purpose. Enabling RLS on `users` is a separate
     * decision, and a test that quietly accepted it would mean nobody noticed
     * it happening.
     */
    public function test_the_scoped_out_tables_are_not_touched(): void
    {
        $enabled = $this->tablesWithRls();

        $this->assertNotContains('users', $enabled);
        $this->assertNotContains('products', $enabled);
    }

    /**
     * No permissive policy was created alongside the flag.
     *
     * RLS with no policy denies to every non-owner. A policy granting anything
     * would leave the table reachable while relrowsecurity stayed true — so "RLS
     * is on" is not the same as "this table is closed", and both need checking.
     */
    public function test_no_permissive_policy_was_created(): void
    {
        $policies = array_filter(
            DB::select('SELECT tablename FROM pg_policies WHERE schemaname = \'public\''),
            fn ($policy): bool => in_array($policy->tablename, self::TABLES, true)
        );

        $this->assertSame([], $policies, 'A policy was added to a covered table. With no policy the table is closed to every non-owner, which is the intent.');
    }

    /**
     * The application's own connection still works, because it is the owner.
     *
     * This is the half that is easy to break by accident: adding FORCE ROW
     * LEVEL SECURITY would leave the application querying zero rows with no error
     * anywhere, and nothing else in the suite would notice.
     */
    public function test_the_owner_connection_still_writes_and_reads(): void
    {
        $city = City::factory()->create(['name' => 'Jakarta']);

        $this->assertSame('Jakarta', $city->fresh()->name);
    }

    public function test_the_test_role_holds_select_so_the_read_assertion_is_not_vacuous(): void
    {
        $pdo = $this->nonOwnerConnection();

        foreach (['users', 'cities', 'sales', 'sale_items'] as $table) {
            $granted = (bool) $pdo->query(
                "SELECT has_table_privilege(current_user, '{$table}', 'SELECT')"
            )?->fetchColumn();

            $this->assertTrue(
                $granted,
                "The test role has no SELECT on {$table}. Postgres checks privileges before policies, "
                    .'so the read assertion would pass without RLS being involved.'
            );
        }
    }

    /**
     * And the point of the whole exercise: a non-owner sees nothing, despite
     * having just been granted everything on the table.
     */
    public function test_a_non_owner_role_cannot_read_the_tables(): void
    {
        $pdo = $this->nonOwnerConnection();

        City::factory()->create(['name' => 'Jakarta']);

        foreach (['cities', 'customers', 'sales', 'sale_items'] as $table) {
            $count = $pdo->query("SELECT count(*) FROM {$table}")?->fetchColumn();

            $this->assertSame(
                '0',
                (string) $count,
                "A non-owner read {$table}. The anon key would see the same rows through PostgREST."
            );
        }
    }

    /**
     * Reading is not enough: the role must not be able to insert either.
     */
    public function test_a_non_owner_role_cannot_write(): void
    {
        $pdo = $this->nonOwnerConnection();

        $this->expectException(\PDOException::class);

        $pdo->exec("INSERT INTO cities (name, created_at, updated_at) VALUES ('Sneaked', now(), now())");
    }

    /**
     * A table with no RLS is readable by the same role on the same connection.
     *
     * This is the control. Without it, a fixture that lost its grants makes every
     * other assertion here pass for the wrong reason.
     */
    public function test_the_same_role_can_read_a_table_without_rls(): void
    {
        $pdo = $this->nonOwnerConnection();

        User::factory()->create(['email' => 'contrast@example.com']);

        $this->assertGreaterThan(
            0,
            (int) $pdo->query('SELECT count(*) FROM users')?->fetchColumn(),
            'users has no RLS by design, so this role must be able to read it. If it cannot, the read '
                .'assertion above is proving nothing.'
        );
    }
}
