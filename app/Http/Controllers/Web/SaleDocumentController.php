<?php

namespace App\Http\Controllers\Web;

use App\Actions\AttachSaleDocument;
use App\Actions\DetachSaleDocument;
use App\Actions\StoreUploadedDocument;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSaleDocumentRequest;
use App\Models\Sale;
use App\Models\SaleDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

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
     *
     * The file is written and the row made in one request, so a document on the
     * sale is a document that exists.
     */
    public function store(StoreSaleDocumentRequest $request, Sale $sale, StoreUploadedDocument $store, AttachSaleDocument $attach): JsonResponse
    {
        try {
            $document = $attach->handle(
                $sale,
                $store->handle($request->file('file')),
                $request->user(),
            );
        } catch (RuntimeException $e) {
            // The reason is returned rather than raised, for the same reason the
            // browser's own upload endpoint does it.
            return response()->json(['message' => $e->getMessage()], 500);
        }

        return response()->json([
            'id' => $document->id,
            'name' => $document->original_name,
            'path' => $document->path,
        ], 201);
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
