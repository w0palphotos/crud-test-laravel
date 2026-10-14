<?php

namespace App\Models;

use Database\Factories\SalespersonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'email', 'phone', 'commission_rate'])]
class Salesperson extends Model
{
    /** @use HasFactory<SalespersonFactory> */
    use HasFactory;

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // A percentage, not money. Read as a string because decimal(5,2)
            // over the wire must not become a float and accumulate error across
            // a commission calculation.
            'commission_rate' => 'decimal:2',
        ];
    }
}
