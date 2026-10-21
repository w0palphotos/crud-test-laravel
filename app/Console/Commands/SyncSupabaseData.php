<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Replace every sales-desk table in the local database with the matching
 * Supabase rows, preserving the source ids so foreign keys carry over.
 *
 * `sale_status_events` is skipped: its `user_id` references the local
 * `users` table, which is not part of this sync.
 */
class SyncSupabaseData extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'db:sync-supabase {--no-fresh : Do not truncate local tables before loading}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Replace local data with data from Supabase';

    private const SOURCE = 'supabase';

    /**
     * Tables that are not synced because they reference the local `users`
     * table, which is not part of this sync.
     *
     * @var array<string, string>
     */
    private const SKIPPED = [
        'sale_status_events' => 'references the local users table',
    ];

    /**
     * Parents before children, so every foreign-key target exists when the
     * referencing row is inserted.
     *
     * @var list<string>
     */
    private const TABLES = [
        'cities',
        'salespeople',
        'categories',
        'suppliers',
        'tags',
        'products',
        'customers',
        'customer_profiles',
        'product_images',
        'category_product',
        'product_supplier',
        'sales',
        'sale_items',
        'payments',
        'sale_tag',
    ];

    /**
     * Children before parents for truncation.
     *
     * @var list<string>
     */
    private const TRUNCATE_ORDER = [
        'sale_tag',
        'payments',
        'sale_items',
        'sales',
        'product_supplier',
        'category_product',
        'product_images',
        'customer_profiles',
        'customers',
        'products',
        'tags',
        'suppliers',
        'categories',
        'salespeople',
        'cities',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->components->info('Reading from Supabase (`'.self::SOURCE.'`) connection.');

        $localUserIds = array_flip(DB::table('users')->pluck('id')->all());

        $counts = [];
        DB::transaction(function () use (&$counts, $localUserIds): void {
            if (! $this->option('no-fresh')) {
                $this->truncateLocal();
            }

            foreach (self::TABLES as $table) {
                $counts[$table] = $this->loadTable($table, $localUserIds);
            }
        });

        $this->components->info('Sync complete.');
        $longest = max(array_map('strlen', self::TABLES));
        foreach (self::TABLES as $table) {
            $this->components->info(
                str_pad($table, $longest).' : '.number_format($counts[$table] ?? 0)
            );
        }

        $skipped = implode(', ', array_keys(self::SKIPPED));
        if ($skipped !== '') {
            $this->components->warn('Skipped: '.$skipped.' (not synced).');
        }

        return 0;
    }

    /**
     * Truncate every synced local table, children before parents.
     */
    private function truncateLocal(): void
    {
        $this->components->info('Truncating local tables.');
        foreach (self::TRUNCATE_ORDER as $table) {
            DB::table($table)->delete();
        }
    }

    /**
     * Copy one table from Supabase into the local database.
     *
     * Rows are inserted in chunks so a large table does not hold every row in
     * memory at once. Source ids are preserved, so foreign keys carry over.
     */
    private function loadTable(string $table, array $localUserIds): int
    {
        $rows = DB::connection(self::SOURCE)->table($table)->get();

        if ($rows->isEmpty()) {
            $this->components->info($table.' : 0 (no source rows)');

            return 0;
        }

        $inserted = 0;
        foreach ($rows->chunk(500) as $chunk) {
            $payload = $chunk->map(function ($row) use ($localUserIds): array {
                $attributes = (array) $row;

                // `user_id` references the local `users` table, which is not
                // part of this sync. Null any id that has no matching local
                // user so the insert does not violate the foreign key.
                if (isset($attributes['user_id'])
                    && $attributes['user_id'] !== null
                    && ! isset($localUserIds[$attributes['user_id']])
                ) {
                    $attributes['user_id'] = null;
                }

                return $attributes;
            })->all();

            DB::table($table)->insert($payload);
            $inserted += $chunk->count();
        }

        $this->components->info($table.' : '.$inserted);

        return $inserted;
    }
}
