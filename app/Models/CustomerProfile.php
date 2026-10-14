<?php

namespace App\Models;

use Database\Factories\CustomerProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['address', 'city_id', 'tax_id', 'credit_limit', 'payment_terms'])]
class CustomerProfile extends Model
{
    /** @use HasFactory<CustomerProfileFactory> */
    use HasFactory;

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
        ];
    }
}
