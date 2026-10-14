<?php

namespace App\Models\Pivots;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * The product/supplier pairing, as a model rather than an anonymous array.
 *
 * Without this the pivot's columns come back as whatever the driver hands over,
 * which for a decimal means a float. Money that becomes a float is money that can
 * be a fraction of a rupiah away from the truth, and the whole reason the rest of
 * this schema casts its amounts to decimal strings.
 *
 * The cast lives here rather than on either side of the relationship because the
 * values belong to neither model: they are true of one product from one supplier
 * and of neither on its own.
 *
 * @property string $unit_cost
 * @property int $lead_time_days
 * @property bool $is_primary
 */
class ProductSupplierPivot extends Pivot
{
    /**
     * The pivot has a composite primary key of two foreign keys and no single
     * auto-incrementing id, so Eloquent must not try to insert one.
     */
    public $incrementing = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_cost' => 'decimal:2',
            'lead_time_days' => 'integer',
            'is_primary' => 'boolean',
        ];
    }
}
