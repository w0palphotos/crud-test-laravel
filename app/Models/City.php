<?php

namespace App\Models;

use Database\Factories\CityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'province', 'user_id'])]
class City extends Model
{
    /** @use HasFactory<CityFactory> */
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Where a sale was delivered.
     *
     * Kept separate from the customer's address, which lives on their profile:
     * a Jakarta branch buying for a Bandung warehouse is a real arrangement, and
     * collapsing the two would lose it.
     */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function customerProfiles(): HasMany
    {
        return $this->hasMany(CustomerProfile::class);
    }
}
