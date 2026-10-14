<?php

namespace App\Actions;

use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\SaleItem;
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
 * The totals are computed here rather than accepted from the request. The form
 * shows a running total to make the arithmetic visible, but a number a browser
 * sent is not a number anyone should trust, and a sale whose stored totals
 * disagree with its own items cannot be reconciled later.
 */
class CreateSale
{
    public function __construct(private readonly SanitiseRichText $sanitise) {}

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
     *     status?: SaleStatus
     * }  $validated
     */
    public function handle(array $validated, ?User $actor = null): Sale
    {
        return DB::transaction(function () use ($validated, $actor): Sale {
            $items = $validated['items'];

            $lines = array_map(
                fn (array $item): array => $this->line($item),
                $items,
            );

            // subtotal is gross, before any discount, and discount_total carries
            // every rupiah off — the line discounts rolled up plus the order one.
            //
            // Computing it the other way round, with subtotal already net of the
            // line discounts, leaves the header unable to show what was taken off
            // the lines at all, so the two halves of a discount are only
            // reconcilable by re-reading every line. This way
            // subtotal - discount_total + tax_total is the invoice, and the same
            // arithmetic in reverse reproduces each figure.
            $gross = $this->money(array_sum(array_map(
                fn (array $line): float => (float) $line['unit_price'] * (int) $line['quantity'],
                $lines,
            )));

            $lineDiscounts = array_sum(array_map(
                fn (array $line): float => (float) $line['discount_total'],
                $lines,
            ));

            $orderDiscount = $this->money($validated['discount_total'] ?? 0);
            $taxTotal = $this->money($validated['tax_total'] ?? 0);
            $discountTotal = $this->money($lineDiscounts + $orderDiscount);

            $sale = Sale::create([
                'reference' => $this->reference(),
                'customer_id' => $validated['customer_id'],
                'salesperson_id' => $validated['salesperson_id'],
                'city_id' => $validated['city_id'],
                'status' => $validated['status'] ?? SaleStatus::Pending,
                'sold_at' => $validated['sold_at'] ?? now(),
                'subtotal' => $gross,
                'discount_total' => $discountTotal,
                'tax_total' => $taxTotal,
                'grand_total' => $this->money((float) $gross - $discountTotal + $taxTotal),

                // Sanitised on the way in, not on the way out. The stored value
                // is therefore already safe to render, so the note field is not a
                // trap for the next view that forgets to escape it.
                'note' => $this->sanitise->handle($validated['note'] ?? null),
            ]);

            foreach ($lines as $line) {
                SaleItem::create($line + ['sale_id' => $sale->id]);
            }

            // A sale always has at least one entry in its history. Without this
            // the status column and the audit trail could disagree with no record
            // of how they came to, which is the one thing an audit trail is for.
            $sale->statusEvents()->create([
                'user_id' => $actor?->id,
                'from_status' => null,
                'to_status' => $sale->status,
                'note' => 'Penjualan dibuat.',
            ]);

            $this->attachTags($sale, $validated['tags'] ?? [], $actor);

            return $sale->load(['items', 'tags', 'customer', 'salesperson', 'city', 'statusEvents']);
        });
    }

    /**
     * One line, with its total derived from the quantity and price rather than
     * read off the request.
     *
     * @param  array{product_id: int, quantity: int, unit_price: string|int|float, discount_total?: string|int|float}  $item
     * @return array<string, mixed>
     */
    private function line(array $item): array
    {
        $quantity = (int) $item['quantity'];
        $unitPrice = $this->money($item['unit_price']);
        $discount = $this->money($item['discount_total'] ?? 0);

        return [
            'product_id' => $item['product_id'],
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'discount_total' => $discount,
            'line_total' => $this->money(($quantity * $unitPrice) - $discount),
        ];
    }

    /**
     * Tags are attached with sync(), not attach(), because the list a form posts
     * is the complete intended set. attach() would only ever add, so removing a
     * tag in the UI would appear to do nothing.
     *
     * @param  array<int, array{id: int, note?: string|null}|int>  $tags
     */
    private function attachTags(Sale $sale, array $tags, ?User $actor): void
    {
        if ($tags === []) {
            return;
        }

        $payload = [];

        foreach ($tags as $tag) {
            // A tag may arrive as a bare id or as {id, note}: the note is what
            // makes this pivot carry data, and a plain multiselect can only send
            // the id.
            $payload[is_array($tag) ? $tag['id'] : $tag] = [
                'note' => is_array($tag) ? ($tag['note'] ?? null) : null,
                'user_id' => $actor?->id,
            ];
        }

        $sale->tags()->sync($payload);
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

    /**
     * Round to two decimals as a string.
     *
     * bcmath is not assumed to be installed, so this is a float rounded once at
     * the boundary. The result is immediately stored in a decimal(14,2) column,
     * which is exact; the only place a float could have mattered is here, and
     * one rounding keeps that from propagating.
     */
    private function money(string|int|float $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
