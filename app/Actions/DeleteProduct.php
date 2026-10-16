<?php

namespace App\Actions;

use App\Models\Product;
use Illuminate\Support\Facades\Storage;

/**
 * Removes a product and the files its gallery owns.
 *
 * The rows need no help: `product_images.product_id` cascades, so deleting the
 * product takes its gallery with it at the database level. The files are a
 * different matter. Nothing in a schema can delete a file, so without this the
 * bucket grows a copy of every product's pictures that nothing points at any
 * more — invisible, unbounded, and paid for.
 *
 * The same reason SyncProductImages may delete: the stored name is a UUID this
 * application generated and one row owns one file, so there is nothing else that
 * could be pointing at it. A sale note's images are HTML a person wrote, where a
 * URL is a reference that cannot be counted — which is why deleting a sale leaves
 * its files on disk, and why this action is about products rather than about
 * images in general.
 *
 * An action rather than a model event so the two doors that delete products — the
 * web controller and the API's — share one behaviour instead of each arranging
 * its own cleanup and one of them being forgotten.
 */
class DeleteProduct
{
    public function handle(Product $product): void
    {
        // Read before the delete: afterwards the rows are gone and the paths with
        // them, and the files would be unreachable.
        $paths = $product->images()->pluck('path')->all();

        $product->delete();

        $disk = Storage::disk(config('images.disk'));

        foreach ($paths as $path) {
            $disk->delete($path);
        }
    }
}
