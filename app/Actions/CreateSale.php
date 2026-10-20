<?php

namespace App\Actions;

use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a sale with its line items, its opening status event and its tags.
 *
 * One transaction, because a sale without its items is not a sale with no items
 * — it is a corrupt record the dashboard would count as revenue. Half of one
 * write must never survive on its own.
 *
 * The totals come from SaleTotals rather than from the request. The form shows a
 * running total to make the arithmetic visible, but a number a browser sent is
 * not a number anyone should trust, and a sale whose stored totals disagree with
 * its own items cannot be reconciled later. UpdateSale takes its figures from the
 * same place, so recording and amending one sale are two doors into one rule.
 */
class CreateSale
{
    public function __construct(
        private readonly SaleTotals $totals,
        private readonly SyncSaleTags $syncTags,
        private readonly AttachSaleDocument $attachDocument,
        private readonly SanitiseRichText $sanitise,
    ) {}

    /**
     * @param  array{
     *     customer_id: int,
     *     salesperson_id: int,
     *     city_id: int,
     *     sold_at?: \DateTimeInterface|null,
     *     discount_total?: string|int|float,
     *     tax_total?: string|int|float,
     *     note?: string|null,
     *     items: array<int, array{product_id: int, quantity: int, unit_price: string|int|float, discount_total?: string|int|float}>,
     *     tags?: array<int, array{id: int, note?: string|null}|int>,
     *     documents?: array<int, array{path: string, original_name: string, mime_type: string, size: int}>,
     *     status?: SaleStatus
     * }  $validated
     */
    public function handle(array $validated, ?User $actor = null): Sale
    {
        return DB::transaction(function () use ($validated, $actor): Sale {
            $totals = $this->totals->handle(
                $validated['items'],
                $validated['discount_total'] ?? null,
                $validated['tax_total'] ?? null,
            );

            $sale = Sale::create([
                'reference' => $this->reference(),
                'customer_id' => $validated['customer_id'],
                'salesperson_id' => $validated['salesperson_id'],
                'city_id' => $validated['city_id'],
                'status' => $validated['status'] ?? SaleStatus::Pending,
                'sold_at' => $validated['sold_at'] ?? now(),
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount_total'],
                'tax_total' => $totals['tax_total'],
                'grand_total' => $totals['grand_total'],

                // Sanitised on the way in, not on the way out. The stored value
                // is therefore already safe to render, so the note field is not a
                // trap for the next view that forgets to escape it.
                'note' => $this->sanitise->handle($validated['note'] ?? null),
            ]);

            $sale->items()->createMany($totals['lines']);

            // A sale always has at least one entry in its history. Without this
            // the status column and the audit trail could disagree with no record
            // of how they came to, which is the one thing an audit trail is for.
            $sale->statusEvents()->create([
                'user_id' => $actor?->id,
                'from_status' => null,
                'to_status' => $sale->status,
                'note' => 'Penjualan dibuat.',
            ]);

            $this->syncTags->handle($sale, $validated['tags'] ?? [], $actor);

            // The documents filed with the sale, in the same transaction as the
            // sale itself. They were written to the disk when they were chosen —
            // the sale they belong to did not exist yet — and what arrives here is
            // a description of each file.
            foreach ($validated['documents'] ?? [] as $document) {
                $this->attachDocument->handle($sale, $document, $actor);
            }

            return $sale->load(['items', 'tags', 'documents', 'customer', 'salesperson', 'city', 'statusEvents']);
        });
    }

    /**
     * A short random reference.
     *
     * Generated here rather than by a database sequence because a sale is
     * quoted to a customer over the phone before it is saved, so the number has
     * to be printable the moment it exists. The collision check is a courtesy;
     * the unique index is what actually guarantees it.
     */
    private function reference(): string
    {
        do {
            $reference = 'SLV-'.Str::upper(Str::random(8));
        } while (Sale::where('reference', $reference)->exists());

        return $reference;
    }
}
