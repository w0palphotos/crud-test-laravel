<?php

namespace App\Http\Controllers\Web;

use App\Actions\AttachSaleDocument;
use App\Actions\DetachSaleDocument;
use App\Actions\StoreUploadedDocument;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSaleDocumentRequest;
use App\Models\Sale;
use App\Models\SaleDocument;
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
     *
     * The reply is a redirect and not a body of json, because this is an Inertia
     * request and Inertia has one shape of answer it will accept. A plain json
     * reply is refused outright, with a complaint that says nothing about the
     * document and everything about the shape of the reply — and the document is
     * already filed by then, so the page would carry a failure for something that
     * worked.
     */
    public function store(StoreSaleDocumentRequest $request, Sale $sale, StoreUploadedDocument $store, AttachSaleDocument $attach): RedirectResponse
    {
        try {
            $attach->handle(
                $sale,
                $store->handle($request->file('file')),
                $request->user(),
            );
        } catch (RuntimeException $e) {
            // The reason is reported to the log and answered to the caller, which
            // is the only place it can be read from: the browser is looking at a
            // form, not at a log.
            report($e);

            return to_route('sales.show', $sale)
                ->withErrors(['file' => $e->getMessage()]);
        }

        return to_route('sales.show', $sale)
            ->with('status', 'Dokumen dilampirkan pada penjualan.');
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
