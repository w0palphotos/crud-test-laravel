<?php

namespace App\Models;

use App\Enums\SaleStatus;
use App\Models\Pivots\SaleTagPivot;
use Database\Factories\SaleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['reference', 'customer_id', 'salesperson_id', 'city_id', 'user_id', 'status', 'sold_at', 'subtotal', 'discount_total', 'tax_total', 'grand_total', 'note'])]
class Sale extends Model
{
    /** @use HasFactory<SaleFactory> */
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(Salesperson::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    /**
     * The second one-to-one in this schema, and a different shape from the
     * customer's: a profile is a record written once and amended, whereas a
     * payment is a settlement that arrives at a point in time. Same relation
     * type, different reason to separate the tables.
     */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    /**
     * Who moved the sale, and when, in order. The `user_id` on each event is the
     * actor rather than an owner, so this is a history and not a hierarchy.
     */
    public function statusEvents(): HasMany
    {
        return $this->hasMany(SaleStatusEvent::class)->orderBy('id');
    }

    /**
     * The documents filed with the sale.
     *
     * A note is markup and a document is a file, and they are kept apart for the
     * same reason: one is text the page shows, the other is a thing the page
     * offers, with a name, a size and a type of its own.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(SaleDocument::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(SaleImage::class)->orderBy('sort_order');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)
            ->using(SaleTagPivot::class)
            ->withPivot(['note', 'user_id', 'created_at']);
    }

    /**
     * Whether the sale still needs the desk's attention.
     *
     * Kept on the model rather than repeated in the dashboard query, because
     * "not settled" is a rule about the lifecycle and the rule belongs next to
     * the lifecycle.
     */
    public function isSettled(): Attribute
    {
        return Attribute::get(fn (): bool => in_array(
            $this->status,
            SaleStatus::settled(),
            true,
        ));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SaleStatus::class,
            'sold_at' => 'datetime',

            // Money stays exact. grand_total is what the dashboard sums over
            // months, and a float would make that sum differ from the same
            // arithmetic done on the stored columns.
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
        ];
    }
}
