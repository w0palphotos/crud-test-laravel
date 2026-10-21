<?php

namespace App\Http\Controllers\Web;

use App\Actions\CreateSale;
use App\Actions\ListSales;
use App\Actions\UpdateSale;
use App\Actions\UpdateSaleStatus;
use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSaleRequest;
use App\Http\Requests\UpdateSaleRequest;
use App\Http\Requests\UpdateSaleStatusEventRequest;
use App\Http\Requests\UpdateSaleStatusRequest;
use App\Models\City;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDocument;
use App\Models\Salesperson;
use App\Models\SaleStatusEvent;
use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Creating, reading and amending a sale.
 *
 * The arithmetic lives in SaleTotals and the validation lives in the request
 * classes, so this controller stays the thin layer those already imply: fetch what
 * the form needs, hand the payload over, redirect.
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
            ->with('status', "Penjualan {$sale->reference} dibuat.");
    }

    /**
     * The detail page, carrying everything an editor needs to open on this sale.
     *
     * The five lists below are the same ones the create form is handed, and for the
     * same reason: an editor that has to fetch them would be unusable when the desk
     * is offline, and a select with a thousand customers and no search is worse than
     * no select at all. They are sent on every load rather than only when editing
     * because Inertia replaces props wholesale, so a page that only fetched them
     * on demand would need a second request the moment "Ubah" was pressed.
     *
     * @return array<string, mixed>
     */
    public function show(Request $request, Sale $sale, ListSales $listSales): Response
    {
        // The gallery behind each thumbnail: one extra query for the whole page
        // rather than one per line, and it is the same relation the catalogue
        // eager-loads to draw its own.
        $sale->load(['items.product.images', 'tags', 'customer', 'salesperson', 'city', 'statusEvents.user']);

        return Inertia::render('Sales/Show', [
            // Whether the signed-in account may change this sale. Hiding the
            // editor is courtesy; the 403 on the write routes is the boundary.
            'can' => ['write' => (bool) $request->user()->is_admin],

            'sale' => [
                'id' => $sale->id,
                'reference' => $sale->reference,
                'status' => $sale->status->value,
                'statusLabel' => $sale->status->label(),
                'soldAt' => $sale->sold_at->translatedFormat('d M Y H:i'),

                // The value a datetime-local input can be bound to, alongside the
                // one above to read. `soldAt` is formatted for a person and cannot
                // be parsed back, so an editor needs the raw local time or every
                // amendment that did not touch the date would quietly move it to
                // midnight — which is also why the editor uses datetime-local
                // rather than the date input the create form has.
                'soldAtInput' => $sale->sold_at->format('Y-m-d\TH:i'),

                'subtotal' => $sale->subtotal,
                'discountTotal' => $sale->discount_total,
                'taxTotal' => $sale->tax_total,
                'grandTotal' => $sale->grand_total,

                // Already sanitised on the way in, by CreateSale or by UpdateSale.
                // The component renders this with v-html, which is only safe
                // because of that — see SanitiseRichText.
                'note' => $sale->note,

                // The names are what the page reads, and the ids are what the
                // editor binds: a select cannot be seeded with a name, and the id
                // is the only thing the request validates.
                'customer' => $sale->customer->name,
                'customerId' => $sale->customer_id,
                'salesperson' => $sale->salesperson->name,
                'salespersonId' => $sale->salesperson_id,
                'city' => $sale->city->name,
                'cityId' => $sale->city_id,
            ],

            // The documents filed with the sale, with a link to each that works
            // for a while.
            //
            // Signed per request, because the bucket is private and a signed link
            // is the only way in. Storing one would make it expire on its own,
            // with nothing to say why; building one here means the link is as
            // fresh as the page offering it.
            'documents' => $this->documents($sale),

            // The endpoint a document is filed through. Built here because
            // route() is a Blade helper the browser cannot reach.
            'documentStoreUrl' => route('sales.documents.store', $sale),

            // Per-record URLs, built here because route() is a Blade helper and
            // rebuilding the shape in the component would put it in two files.
            'updateUrl' => route('sales.update', $sale),

            'customers' => Customer::query()->orderBy('name')->get(['id', 'name'])->all(),
            'salespeople' => Salesperson::query()->orderBy('name')->get(['id', 'name'])->all(),
            'cities' => City::query()->orderBy('name')->get(['id', 'name'])->all(),

            // The whole catalogue of tags, for the editor's choices. Distinct from
            // `tags` below, which is only the ones this sale already carries.
            'tagOptions' => Tag::query()->orderBy('name')->get(['id', 'name', 'colour'])->all(),
            'products' => Product::query()
                // Only the fields the editor needs to price a line. The catalogue
                // is the largest table in the application and the form shows two
                // columns of it.
                ->orderBy('name')
                ->get(['id', 'name', 'price', 'stock'])
                ->all(),

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

                // The head of the gallery, which is the row the relation orders
                // first — the same "first row is the main picture" rule the
                // catalogue follows. Null for a product with none, rather than
                // omitted, so the component reads one key either way.
                'thumbnailUrl' => $item->product->images->first()?->url,
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
                'id' => $event->id,
                'from' => $event->from_status?->value,
                'fromLabel' => $event->from_status?->label(),
                'to' => $event->to_status->value,
                'toLabel' => $event->to_status->label(),
                'note' => $event->note,
                'user' => $event->user?->name,
                'at' => $event->created_at->translatedFormat('d M Y H:i'),
                'updateUrl' => route('sales.status-events.update', [$sale, $event]),
            ])->all(),
        ]);
    }

    /**
     * Amend the sale itself: its header, its lines, its money, its tags and its note.
     *
     * Total replacement rather than a patch. The form posts every field every time,
     * so UpdateSale treats the payload as the whole truth and recomputes the totals
     * from the lines rather than accepting any of them.
     */
    public function update(UpdateSaleRequest $request, Sale $sale, UpdateSale $updateSale): RedirectResponse
    {
        $updateSale->handle($sale, $request->validated(), $request->user());

        return to_route('sales.show', $sale)
            ->with('status', 'Penjualan diperbarui.');
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

    /**
     * Amend the note on one entry in the sale's history.
     *
     * The event is fetched through the sale rather than bound off the URL on its
     * own. Both ids appear in the path, and binding them independently would accept
     * any pair — so a request naming this sale and somebody else's event would
     * rewrite the wrong history entry with nothing to say so.
     */
    public function updateStatusEvent(
        UpdateSaleStatusEventRequest $request,
        Sale $sale,
        SaleStatusEvent $event,
    ): RedirectResponse {
        $sale->statusEvents()->findOrFail($event->getKey())
            ->update(['note' => $request->input('note')]);

        return to_route('sales.show', $sale)
            ->with('status', 'Catatan riwayat diperbarui.');
    }

    /**
     * The documents filed with the sale, each with a link that works for a while.
     *
     * A document whose link cannot be made is still listed, without one. A sale
     * that had a document filed and now cannot show it is worse than one that
     * never had it, so the failure is per document and does not take the page with
     * it.
     *
     * @return list<array<string, mixed>>
     */
    private function documents(Sale $sale): array
    {
        $minutes = (int) config('documents.signed_url_minutes');

        return $sale->documents()
            ->with('uploader')
            ->get()
            ->map(fn (SaleDocument $document): array => [
                'id' => $document->id,
                'name' => $document->original_name,
                'mimeType' => $document->mime_type,
                'size' => $document->size,
                'readableSize' => $document->readableSize(),
                'uploadedBy' => $document->uploader?->name,
                'uploadedAt' => $document->created_at->translatedFormat('d M Y H:i'),
                'url' => $this->link($document, $minutes),

                // The endpoint that takes it off the sale, built here for the
                // same reason every other url on this page is.
                'destroyUrl' => route('sales.documents.destroy', [$sale, $document]),
            ])
            ->all();
    }

    /**
     * A link to the document, or nothing when one cannot be made.
     */
    private function link(SaleDocument $document, int $minutes): ?string
    {
        try {
            return $document->temporaryUrl($minutes);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
