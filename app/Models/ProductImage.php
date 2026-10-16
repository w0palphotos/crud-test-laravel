<?php

namespace App\Models;

use Database\Factories\ProductImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['product_id', 'path', 'alt_text', 'sort_order'])]
class ProductImage extends Model
{
    /** @use HasFactory<ProductImageFactory> */
    use HasFactory;

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Where this image can be seen.
     *
     * An accessor rather than something each caller assembles, for two reasons.
     * Which disk serves images is configuration, and a browser cannot read config —
     * so somebody has to cross that line, and it should be in one place. And three
     * surfaces now want this same answer: the gallery a product form loads, the
     * thumbnail in the product table, and the API resource. Three call sites
     * building a URL is three places for the production disk name to go missing.
     */
    protected function url(): Attribute
    {
        return Attribute::get(fn (): string => Storage::disk(config('images.disk'))->url($this->path));
    }
}
