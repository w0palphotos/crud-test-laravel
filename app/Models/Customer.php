<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'email', 'phone', 'user_id'])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /**
     * The one-to-one half of the split described in the customers migration.
     *
     * hasOne, not hasOneThrough or belongsTo: a customer has at most one
     * profile, and the profile's `customer_id` is unique in the database, so
     * this can never return a surprise second row. What it can return is null,
     * and that is meaningful: nobody at the desk has onboarded them yet.
     *
     * withDefault() would be wrong here. An empty profile would read as "onboarded
     * with no address, a zero credit limit and COD terms", which is a statement
     * about the customer. A missing profile says only that we have not asked.
     */
    public function profile(): HasOne
    {
        return $this->hasOne(CustomerProfile::class);
    }
}
