<?php

namespace App\Models;

use App\Models\Pivots\SaleTagPivot;
use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'colour'])]
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    /**
     * The second pivot that carries data: a note explaining why this tag is on
     * this particular sale.
     */
    public function sales(): BelongsToMany
    {
        return $this->belongsToMany(Sale::class)
            ->using(SaleTagPivot::class)
            ->withPivot(['note', 'user_id', 'created_at']);
    }
}
