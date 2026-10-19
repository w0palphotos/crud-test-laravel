<?php

namespace App\Actions;

use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Amends an existing sale: its header, its lines, its money, its tags and its note.
 *
 * One transaction, for the same reason CreateSale has one, plus the fact that here
 * the sale already exists — half of this write would leave a live sale whose stored
 * totals describe items it no longer has.
 *
 * The lines are replaced wholesale rather than reconciled one by one. The list a
 * form posts is the complete intended set, exactly as it is on creation, so
 * "the rows now are these rows" is the whole meaning of the write and diffing them
 * would only buy the possibility of a partially applied set surviving a failure.
 * Nothing points at an individual line: no table has a foreign key to sale_items,
 * so the rows can be rewritten without anything else following them.
 *
 * The row is locked before anything is read back, because two administrators can
 * hold the editor open on the same sale and the second save would otherwise
 * overwrite the first's lines with figures computed from a form that never saw
 * them. Same rule, same reason as UpdateSaleStatus's lock.
 *
 * The reference and the status are not fields here. The reference is quoted to a
 * customer and has to stay the same number; the status is a lifecycle move and
 * belongs to UpdateSaleStatus, which is what checks the transition is legal and
 * writes the event that says so.
 */
class UpdateSale
{
    public function __construct(
        private readonly SaleTotals $totals,
        private readonly SyncSaleTags $syncTags,
        private readonly SanitiseRichText $sanitise,
    ) {}

    /**
     * @param  array{
     *     customer_id: int,
     *     salesperson_id: int,
     *     city_id: int,
     *     sold_at?: \DateTimeInterface|null,
     *     discount_total?: string|int|float|null,
     *     tax_total?: string|int|float|null,
     *     note?: string|null,
     *     items: array<int, array{product_id: int, quantity: int, unit_price: string|int|float, discount_total?: string|int|float}>
     * }  $validated
     */
    public function handle(Sale $sale, array $validated, ?User $actor = null): Sale
    {
        return DB::transaction(function () use ($sale, $validated, $actor): Sale {
            $sale = Sale::whereKey($sale->getKey())->lockForUpdate()->firstOrFail();

            // The same figures the create path would have computed from these same
            // lines. A total sent by the browser is not a number anyone should
            // store, so there is nowhere for one to arrive here.
            $totals = $this->totals->handle(
                $validated['items'],
                $validated['discount_total'] ?? null,
                $validated['tax_total'] ?? null,
            );

            $sale->update([
                'customer_id' => $validated['customer_id'],
                'salesperson_id' => $validated['salesperson_id'],
                'city_id' => $validated['city_id'],

                // Left as it stands when the form sends no date, rather than
                // snapped to now(): an editor that submits the record unchanged
                // must not restate when the sale happened.
                'sold_at' => $validated['sold_at'] ?? $sale->sold_at,

                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount_total'],
                'tax_total' => $totals['tax_total'],
                'grand_total' => $totals['grand_total'],

                // Sanitised on the way in, exactly as on creation. The detail page
                // renders the stored value with v-html and there is nothing at
                // render time to stop it, so this is the only place the note can be
                // made safe — and an amendment is a second door into it.
                'note' => $this->sanitise->handle($validated['note'] ?? null),
            ]);

            $sale->items()->delete();
            $sale->items()->createMany($totals['lines']);

            $this->syncTags->handle($sale, $validated['tags'] ?? [], $actor);

            // A sale that changed without leaving anything on its history reads as
            // a sale that never changed. from_status equals to_status because the
            // status did not move: this entry records an amendment to the record,
            // not a transition of the sale, and the detail page collapses the two
            // into one label for that reason.
            $sale->statusEvents()->create([
                'user_id' => $actor?->id,
                'from_status' => $sale->status,
                'to_status' => $sale->status,
                'note' => 'Penjualan diubah.',
            ]);

            return $sale->load(['items', 'tags', 'customer', 'salesperson', 'city', 'statusEvents']);
        });
    }
}
