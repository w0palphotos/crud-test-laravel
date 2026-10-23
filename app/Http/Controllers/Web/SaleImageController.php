<?php

namespace App\Http\Controllers\Web;

use App\Actions\AttachSaleImage;
use App\Actions\StoreUploadedImage;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUploadRequest;
use App\Models\Sale;
use App\Models\SaleImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The images filed with a sale.
 *
 * Two writes and nothing else, mirroring SaleDocumentController. An image is
 * written to the public images disk and a row is made on sale_images; the two
 * live on different disks and different tables from the documents beside them,
 * which is the whole reason this endpoint exists apart from the document one.
 */
class SaleImageController extends Controller
{
    /**
     * File an image with the sale.
     *
     * The file is written and the row made in one request, so an image on the
     * sale is an image that exists.
     */
    public function store(
        StoreUploadRequest $request,
        Sale $sale,
        StoreUploadedImage $store,
        AttachSaleImage $attach
    ): RedirectResponse {
        try {
            $attach->handle(
                $sale,
                $store->handle($request->file('image')),
                $sale->images()->count(),
            );
        } catch (RuntimeException $e) {
            report($e);

            return to_route('sales.show', $sale)
                ->withErrors(['image' => $e->getMessage()]);
        }

        return to_route('sales.show', $sale)
            ->with('status', 'Gambar dilampirkan pada penjualan.');
    }

    /**
     * Take the image off the sale.
     *
     * The image is looked up through the sale, so a request naming this sale
     * and somebody else's image cannot reach it.
     */
    public function destroy(Request $request, Sale $sale, SaleImage $image): RedirectResponse
    {
        $image = $sale->images()->findOrFail($image->getKey());

        $path = $image->path;

        $image->delete();

        try {
            Storage::disk(config('images.disk'))->delete($path);
        } catch (\Throwable $e) {
            // Reported, not raised: the row is off the sale, which is what was
            // asked for. The file is unreachable rather than mislaid.
            report($e);
        }

        return to_route('sales.show', $sale)
            ->with('status', 'Gambar dihapus dari penjualan.');
    }
}
