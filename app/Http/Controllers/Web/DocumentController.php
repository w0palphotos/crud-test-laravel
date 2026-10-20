<?php

namespace App\Http\Controllers\Web;

use App\Actions\StoreUploadedDocument;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSaleDocumentRequest;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Writes a document to the documents disk, with no sale attached to it.
 *
 * The create form files its documents before the sale exists, so the file has to
 * be written first and attached afterwards. The same endpoint serves the sale's
 * own page, which is why there is one place a document is written and not two.
 *
 * What comes back is a description of the file — its path, its name, its size and
 * its type — and the path is what is kept. A url would be signed and would
 * expire, and a url written into a column stops working on its own with nothing to
 * say why.
 */
class DocumentController extends Controller
{
    public function store(StoreSaleDocumentRequest $request, StoreUploadedDocument $store): JsonResponse
    {
        try {
            return response()->json($store->handle($request->file('file')), 201);
        } catch (RuntimeException $e) {
            // The reason is returned rather than raised: "the bucket was never
            // named" is a sentence an administrator can act on, and the storage
            // client's own answer is a type error from inside its constructor.
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
