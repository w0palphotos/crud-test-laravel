<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'slug', 'user_id'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The plain many-to-many: a product is in any number of categories and a
     * category holds any number of products, with nothing about the pairing
     * beyond its existence. The pivot carries no columns, so it is the case that
     * `attach()` and `sync()` alone describe completely.
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }
}
