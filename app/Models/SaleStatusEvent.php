<?php

namespace App\Models;

use App\Enums\SaleStatus;
use Database\Factories\SaleStatusEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['sale_id', 'user_id', 'from_status', 'to_status', 'note'])]
class SaleStatusEvent extends Model
{
    /** @use HasFactory<SaleStatusEventFactory> */
    use HasFactory;

    /**
     * Written once and never edited, so there is nothing to update and the
     * timestamp that matters is created_at alone.
     */
    public const UPDATED_AT = null;

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * Who made the change. Null when the person has since left: the event stays
     * on the sale either way, because an unexplained status change is worse than
     * one attributed to a departed colleague.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => SaleStatus::class,
            'to_status' => SaleStatus::class,
        ];
    }
}
