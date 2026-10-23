<?php

namespace App\Models;

use Database\Factories\SaleImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * An image filed with a sale.
 *
 * The file lives on the images disk, in the public bucket, and only the path to
 * it is stored. A url would have been easier to keep and worse to have: a signed
 * url expires, and one written into a column would stop working on its own with
 * nothing to say why.
 */
#[Fillable(['sale_id', 'path', 'sort_order'])]
class SaleImage extends Model
{
    /** @use HasFactory<SaleImageFactory> */
    use HasFactory;

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * Where this image can be seen.
     *
     * An accessor rather than something each caller assembles, for two reasons.
     * Which disk serves images is configuration, and a browser cannot read config
     * — so somebody has to cross that line, and it should be in one place.
     */
    protected function url(): Attribute
    {
        return Attribute::get(fn (): string => Storage::disk(config('images.disk'))->url($this->path));
    }
}
