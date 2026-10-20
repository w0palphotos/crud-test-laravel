<?php

namespace App\Http\Controllers\Web;

use App\Actions\AttachSaleDocument;
use App\Actions\DetachSaleDocument;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSaleDocumentRequest;
use App\Models\Sale;
use App\Models\SaleDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The documents filed with a sale.
 *
 * Two writes and nothing else. The list is not here: it travels with the sale's
 * own page, because a document is part of the sale and the sale's page is where
 * somebody looks for what a sale was made of.
 *
 * A link to a document is signed and expires, so it cannot be sent once and
 * kept. That is why the list is built per request rather than stored.
 */
class SaleDocumentController extends Controller
{
    /**
     * File a document with the sale.
     */
    public function store(StoreSaleDocumentRequest $request, Sale $sale, AttachSaleDocument $attach): JsonResponse
    {
        $document = $attach->handle($sale, $request->file('file'), $request->user());

        return response()->json($document, 201);
    }

    /**
     * Take the document off the sale.
     *
     * The document is looked up through the sale, so a request naming this sale
     * and somebody else's document cannot reach it.
     */
    public function destroy(Request $request, Sale $sale, SaleDocument $document, DetachSaleDocument $detach): RedirectResponse
    {
        $document = $sale->documents()->findOrFail($document->getKey());

        $detach->handle($document);

        return to_route('sales.show', $sale)
            ->with('status', 'Dokumen dihapus dari penjualan.');
    }
}
