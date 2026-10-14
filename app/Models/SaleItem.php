<?php

namespace App\Models;

use Database\Factories\SaleItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['sale_id', 'product_id', 'quantity', 'unit_price', 'discount_total', 'line_total'])]
class SaleItem extends Model
{
    /** @use HasFactory<SaleItemFactory> */
    use HasFactory;

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * The catalogue product this was priced from.
     *
     * restrictOnDelete on the column, so a product cannot be deleted while a
     * sale line still points at it. The line is the historical record of what a
     * customer paid; deleting the product would not remove the obligation, and
     * nulling the reference would turn a real sale into an unpriceable one.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }
}
