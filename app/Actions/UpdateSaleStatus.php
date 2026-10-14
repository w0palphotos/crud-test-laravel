<?php

namespace App\Actions;

use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateSaleStatus
{
    /**
     * Move a sale to an allowed next status and record the move.
     *
     * The status column and its history are updated in one transaction. A status
     * without a matching event would leave the ledger and its audit trail
     * disagreeing, while an event without the status change would describe a
     * move that never happened.
     *
     * The transition is checked again here even though the form request already
     * checked it. Validation runs before the write, so two requests can both
     * pass against the same starting status; the row lock makes the second one
     * see the first one's result.
     *
     * @throws ValidationException
     */
    public function handle(Sale $sale, SaleStatus $to, ?User $actor = null, ?string $note = null): Sale
    {
        return DB::transaction(function () use ($sale, $to, $actor, $note): Sale {
            $sale = Sale::whereKey($sale->getKey())->lockForUpdate()->firstOrFail();
            $from = $sale->status;

            if (! $from->canTransitionTo($to)) {
                throw ValidationException::withMessages([
                    'status' => ['Penjualan tidak dapat berpindah ke status tersebut dari status saat ini.'],
                ]);
            }

            $sale->status = $to;
            $sale->save();

            $sale->statusEvents()->create([
                'user_id' => $actor?->id,
                'from_status' => $from,
                'to_status' => $to,
                'note' => $note,
            ]);

            return $sale->load(['items', 'tags', 'customer', 'salesperson', 'city', 'statusEvents']);
        });
    }
}
