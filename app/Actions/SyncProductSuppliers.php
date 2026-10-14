<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

/**
 * Replaces a product's supplier list with the one that was submitted.
 *
 * sync() rather than attach() or a loop of save() calls. A form posting the
 * supplier table submits the complete intended set, so the write has to be a
 * replacement: attach() would only ever add, and removing a supplier in the UI
 * would appear to do nothing. sync() also runs as one statement pair rather
 * than one query per row.
 *
 * The pivot columns are the reason this is an action and not a call site detail.
 * unit_cost and lead_time_days belong to the pairing, so writing them is part of
 * maintaining the relationship, and the is_primary rule below is a constraint on
 * the whole set rather than on any single row.
 */
class SyncProductSuppliers
{
    /**
     * @param  array<int, array{supplier_id: int, unit_cost: string|int|float, lead_time_days?: int, is_primary?: bool}>  $rows
     */
    public function handle(Product $product, array $rows): Product
    {
        $payload = [];

        foreach ($rows as $row) {
            $payload[(int) $row['supplier_id']] = [
                'unit_cost' => number_format((float) $row['unit_cost'], 2, '.', ''),
                'lead_time_days' => (int) ($row['lead_time_days'] ?? 0),
                'is_primary' => (bool) ($row['is_primary'] ?? false),
            ];
        }

        return DB::transaction(function () use ($product, $payload): Product {
            $product->suppliers()->sync($payload);

            $this->enforceSinglePrimary($product);

            return $product->load('suppliers');
        });
    }

    /**
     * Demote every supplier that is not the marked primary.
     *
     * The database does not enforce this, deliberately: a partial unique index
     * would make "two suppliers on file, none chosen yet" impossible to record,
     * and that is a real state during a negotiation. What must not survive is
     * two primaries silently disagreeing about where stock comes from, so the
     * last one marked wins rather than both standing.
     */
    private function enforceSinglePrimary(Product $product): void
    {
        $primary = $product->suppliers()->wherePivot('is_primary', true)->first();

        if ($primary === null) {
            return;
        }

        // Straight at the pivot table. BelongsToMany::update() writes through the
        // related model, so qualifying the column with the pivot's table name
        // produces `update "suppliers" set "is_primary" = 0` and a column that
        // does not exist on that table.
        DB::table('product_supplier')
            ->where('product_id', $product->getKey())
            ->where('supplier_id', '!=', $primary->getKey())
            ->where('is_primary', true)
            ->update(['is_primary' => false]);
    }

    /**
     * The supplier the desk reaches for first, or null when none is marked.
     */
    public function primaryFor(Product $product): ?Supplier
    {
        return $product->suppliers()->wherePivot('is_primary', true)->first();
    }
}
