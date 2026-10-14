<?php

namespace App\Http\Controllers\Web;

use App\Actions\CreateSale;
use App\Actions\ListSales;
use App\Actions\UpdateSaleStatus;
use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSaleRequest;
use App\Http\Requests\UpdateSaleStatusRequest;
use App\Models\City;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Salesperson;
use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Creating and reading a sale.
 *
 * The arithmetic lives in CreateSale and the validation lives in
 * StoreSaleRequest, so this controller stays the thin layer that the two already
 * imply: fetch what the form needs, hand the payload over, redirect.
 */
class SaleController extends Controller
{
    /**
     * Everything the form offers as a choice, ordered by name.
     *
     * Keyset-free simple ordering because these lists are for selection, not
     * browsing — there is no page to be on. Sent whole rather than fetched by
     * the browser because the sale form needs all of them at once to stay usable
     * offline; a select with a thousand customers and no search is worse than no
     * select at all.
     */
    public function create(): Response
    {
        return Inertia::render('Sales/Form', [
            'customers' => Customer::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->all(),
            'salespeople' => Salesperson::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->all(),
            'cities' => City::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->all(),
            'tags' => Tag::query()
                ->orderBy('name')
                ->get(['id', 'name', 'colour'])
                ->all(),
            'products' => Product::query()
                // Only the fields the form needs to price a line. The catalogue
                // is the largest table in the application and the form shows two
                // columns of it.
                ->orderBy('name')
                ->get(['id', 'name', 'price', 'stock'])
                ->all(),
        ]);
    }

    public function store(StoreSaleRequest $request, CreateSale $createSale): RedirectResponse
    {
        $sale = $createSale->handle($request->validated(), $request->user());

        return to_route('sales.show', $sale)
            ->with('success', "Penjualan {$sale->reference} dibuat.");
    }

    public function show(Request $request, Sale $sale, ListSales $listSales): Response
    {
        $sale->load(['items.product', 'tags', 'customer', 'salesperson', 'city', 'statusEvents.user']);

        return Inertia::render('Sales/Show', [
            'sale' => [
                'id' => $sale->id,
                'reference' => $sale->reference,
                'status' => $sale->status->value,
                'statusLabel' => $sale->status->label(),
                'soldAt' => $sale->sold_at->translatedFormat('d M Y H:i'),
                'subtotal' => $sale->subtotal,
                'discountTotal' => $sale->discount_total,
                'taxTotal' => $sale->tax_total,
                'grandTotal' => $sale->grand_total,
                // Already sanitised by CreateSale on the way in. The component
                // renders this with v-html, which is only safe because of that —
                // see SanitiseRichText.
                'note' => $sale->note,
                'customer' => $sale->customer->name,
                'salesperson' => $sale->salesperson->name,
                'city' => $sale->city->name,
            ],
            'nextStatuses' => collect($sale->status->nextStates())
                ->map(fn ($status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ])
                ->all(),
            'statusUpdateUrl' => route('sales.status.update', $sale),
            'items' => $sale->items->map(fn ($item): array => [
                'product' => $item->product->name,
                'productId' => $item->product_id,
                'quantity' => $item->quantity,
                'unitPrice' => $item->unit_price,
                'discountTotal' => $item->discount_total,
                'lineTotal' => $item->line_total,
            ])->all(),
            'tags' => $sale->tags->map(fn ($tag): array => [
                'id' => $tag->id,
                'name' => $tag->name,
                'colour' => $tag->colour,
            ])->all(),
            'statusEvents' => $sale->statusEvents->map(fn ($event): array => [
                'from' => $event->from_status?->value,
                'fromLabel' => $event->from_status?->label(),
                'to' => $event->to_status->value,
                'toLabel' => $event->to_status->label(),
                'note' => $event->note,
                'user' => $event->user?->name,
                'at' => $event->created_at->translatedFormat('d M Y H:i'),
            ])->all(),
        ]);
    }

    public function updateStatus(UpdateSaleStatusRequest $request, Sale $sale, UpdateSaleStatus $updateStatus): RedirectResponse
    {
        $updateStatus->handle(
            $sale,
            $request->enum('status', SaleStatus::class),
            $request->user(),
            $request->input('note'),
        );

        return to_route('sales.show', $sale)
            ->with('status', 'Status penjualan diperbarui.');
    }
}
