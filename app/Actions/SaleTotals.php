<?php

namespace App\Actions;

/**
 * What a set of sale lines is worth.
 *
 * One definition of the arithmetic, shared by CreateSale and UpdateSale. The two
 * write paths would otherwise each carry their own copy, and copies of arithmetic
 * drift: the same numbers stored through the form and the same numbers stored
 * through the editor a month later would land in the column under two different
 * rules, and neither the difference nor its cause would be recoverable.
 *
 * Nothing here reads the request. It is handed quantities and prices and returns
 * what they add up to, so no caller can widen it into "trust the total the browser
 * sent" by passing a total in.
 */
class SaleTotals
{
    /**
     * The lines to store, and the four header figures they roll up into.
     *
     * subtotal is gross, before any discount, and discount_total carries every
     * rupiah off — the line discounts rolled up plus the order one.
     *
     * Computing it the other way round, with subtotal already net of the line
     * discounts, leaves the header unable to show what was taken off the lines at
     * all, so the two halves of a discount would only be reconcilable by re-reading
     * every line. This way subtotal - discount_total + tax_total is the invoice,
     * and the same arithmetic in reverse reproduces each figure.
     *
     * @param  array<int, array{product_id: int, quantity: int, unit_price: string|int|float, discount_total?: string|int|float}>  $items
     * @return array{
     *     lines: list<array{product_id: int, quantity: int, unit_price: string, discount_total: string, line_total: string}>,
     *     subtotal: string,
     *     discount_total: string,
     *     tax_total: string,
     *     grand_total: string,
     * }
     */
    public function handle(array $items, string|int|float|null $orderDiscount, string|int|float|null $tax): array
    {
        $lines = array_map(
            fn (array $item): array => $this->line($item),
            $items,
        );

        $gross = $this->money(array_sum(array_map(
            fn (array $line): float => (float) $line['unit_price'] * (int) $line['quantity'],
            $lines,
        )));

        $lineDiscounts = array_sum(array_map(
            fn (array $line): float => (float) $line['discount_total'],
            $lines,
        ));

        $orderDiscount = $this->money($orderDiscount ?? 0);
        $taxTotal = $this->money($tax ?? 0);
        $discountTotal = $this->money($lineDiscounts + $orderDiscount);

        return [
            'lines' => $lines,

            'subtotal' => $gross,
            'discount_total' => $discountTotal,
            'tax_total' => $taxTotal,
            'grand_total' => $this->money((float) $gross - $discountTotal + $taxTotal),
        ];
    }

    /**
     * One line, with its total derived from the quantity and price rather than
     * read off the request.
     *
     * @param  array{product_id: int, quantity: int, unit_price: string|int|float, discount_total?: string|int|float}  $item
     * @return array{product_id: int, quantity: int, unit_price: string, discount_total: string, line_total: string}
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
