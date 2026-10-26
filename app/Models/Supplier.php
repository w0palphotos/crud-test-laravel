<?php

namespace App\Models;

use App\Models\Pivots\ProductSupplierPivot;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'contact_email', 'phone', 'user_id'])]
class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A many-to-many that carries data.
     *
     * The cost we pay a supplier and their lead time belong to the pairing, not
     * to either side: the same product may cost one supplier less and arrive
     * sooner from another, and the same supplier may price two products
     * differently. Putting those columns on `products` or on `suppliers` would
     * force one of the two facts to be a lie.
     *
     * withPivot() is what makes them readable here. Reading `unit_cost` without
     * it yields null, which is the kind of quiet wrongness that looks like a
     * missing join.
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)
            ->using(ProductSupplierPivot::class)
            ->withPivot(['unit_cost', 'lead_time_days', 'is_primary']);
    }
}
