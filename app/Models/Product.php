<?php

namespace App\Models;

use App\Models\Pivots\ProductSupplierPivot;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'description_html', 'price', 'stock'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    /**
     * A many-to-many carrying the cost and lead time agreed with each supplier.
     *
     * See Supplier::products() for why those facts live on the pairing.
     */
    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class)
            ->using(ProductSupplierPivot::class)
            ->withPivot(['unit_cost', 'lead_time_days', 'is_primary']);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * Price is stored as a decimal(10, 2) column but is exposed as a JSON number
     * so requests and responses agree on the type. The `decimal:0,2` validation
     * rule, not the cast, is what caps incoming values at two decimal places.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'float',
        ];
    }
}
