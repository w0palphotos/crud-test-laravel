<?php

namespace App\Models\Pivots;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * The sale/tag pairing.
 *
 * Carries three things that are not boolean: the note explaining why the tag is
 * on this sale, who attached it, and when. The timestamp in particular needs a
 * cast — as an uncast string it sorts lexicographically rather than
 * chronologically in a listing, which is wrong the moment two land on the same
 * day.
 *
 * @property string|null $note
 * @property int|null $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SaleTagPivot extends Pivot
{
    /**
     * Composite primary key, and no auto-incrementing id to invent.
     */
    public $incrementing = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
