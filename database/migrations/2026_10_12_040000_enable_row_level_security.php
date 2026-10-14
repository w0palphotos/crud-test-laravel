<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turns on Row Level Security for the sales desk tables.
 *
 * This app talks to Postgres through Eloquent and nothing else. The Supabase
 * project's own interfaces — PostgREST, the Storage API's database access, the
 * dashboard's table editor under the anon and authenticated roles — are a second
 * door into the same database, and this is what closes them.
 *
 * Two things make that work, and both are counter-intuitive enough to be worth
 * stating:
 *
 * **The policies are deliberately absent.** A table with RLS enabled and no
 * policy denies everything to every role except the table owner. That is the
 * whole of the intent here: this is a single-tenant internal application, and
 * the tables should be unreachable except through the application. A permissive
 * policy would undo the point.
 *
 * **The application's own connection is unaffected because it is the owner.**
 * RLS does not apply to a table's owner unless FORCE ROW LEVEL SECURITY is also
 * set. The app connects as postgres.PROJECTREF, which owns these tables, so it
 * keeps full access. Adding FORCE would make the application see zero rows, and
 * it is not in this migration for exactly that reason.
 *
 * Scoped to the sales desk tables. `users` and `products` are left alone: both
 * predate this feature, and enabling RLS on them is a separate decision with its
 * own migration, not something to slip into the middle of this one.
 *
 * `storage.objects` is deliberately untouched — Supabase manages its own policies
 * through the dashboard, and editing them from an application migration would
 * fight with that.
 *
 * Driver-guarded because this project runs on SQLite in development and in the
 * default test suite. ALTER TABLE ... ENABLE ROW LEVEL SECURITY is Postgres-only,
 * and unguarded it would fail every local migrate and every RefreshDatabase.
 */
return new class extends Migration
{
    /**
     * The tables this covers.
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

    public function up(): void
    {
        $this->forEachTable(function (string $table, string $statement): void {
            DB::statement($statement);
        }, 'ENABLE ROW LEVEL SECURITY');
    }

    public function down(): void
    {
        $this->forEachTable(function (string $table, string $statement): void {
            DB::statement($statement);
        }, 'DISABLE ROW LEVEL SECURITY');
    }

    /**
     * @param  callable(string, string): void  $callback
     */
    private function forEachTable(callable $callback, string $clause): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TABLES as $table) {
            // The list is a literal in this file rather than user input, but the
            // check costs nothing and would turn a careless edit into a syntax
            // error rather than a dropped table.
            if (! Schema::hasTable($table)) {
                continue;
            }

            $callback($table, "ALTER TABLE {$table} {$clause}");
        }
    }
};
