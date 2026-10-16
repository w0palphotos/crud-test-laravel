<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Replaces a product's gallery with the list that was submitted.
 *
 * A replacement rather than a merge, for the reason SyncProductSuppliers gives: the
 * form posts the complete intended list, so an attach-only write would make removing
 * an image in the UI look like it did nothing. The list's order is the gallery's
 * order — the index becomes sort_order — so the arrangement the desk made is the
 * arrangement the catalogue shows.
 *
 * It deletes files, and that is the part product_images can do which the sale note's
 * inline images cannot. It is safe here because the stored name is a UUID this
 * application generated: one row owns one file, so nothing else can be pointing at
 * it. A note's images are HTML that a person wrote, where a URL is a reference that
 * cannot be counted — which is why deleting a sale leaves its files on disk.
 */
class SyncProductImages
{
    /**
     * @param  array<int, array{id?: int|string, path?: string, alt_text?: string|null}>  $rows
     */
    public function handle(Product $product, array $rows): Product
    {
        return DB::transaction(function () use ($product, $rows): Product {
            $kept = [];

            foreach (array_values($rows) as $order => $row) {
                $alt = $row['alt_text'] ?? null;

                // An empty caption and an absent one mean the same thing and the
                // column is nullable. Keeping both spellings of "none" would make
                // every later query ask twice for the same answer.
                $alt = is_string($alt) && trim($alt) !== '' ? trim($alt) : null;

                if (isset($row['id'])) {
                    // Read through the relation, so an id belonging to someone else's
                    // gallery is a 404 rather than a way to move their image into this
                    // product's.
                    $image = $product->images()->findOrFail((int) $row['id']);

                    $image->update(['alt_text' => $alt, 'sort_order' => $order]);
                } else {
                    $image = $product->images()->create([
                        'path' => $row['path'],
                        'alt_text' => $alt,
                        'sort_order' => $order,
                    ]);
                }

                $kept[] = $image->getKey();
            }

            // An empty list means every image was removed, which is why this is a
            // whereNotIn over the kept ids rather than a query over the submitted
            // ones: it is the same statement whether one row was dropped or all of
            // them were.
            $removed = $product->images()->whereNotIn('id', $kept)->get();

            foreach ($removed as $image) {
                $image->delete();
            }

            // The files go after the rows, not before. A row whose file is missing
            // renders as a broken thumbnail and can be seen and fixed; a file whose
            // row is gone is invisible, so if only one of the two can survive an
            // interruption it should be the row.
            foreach ($removed as $image) {
                Storage::disk(config('images.disk'))->delete($image->path);
            }

            return $product->load('images');
        });
    }

    /**
     * The gallery, ready for a page: the stored columns plus a URL to show them with.
     *
     * The URL comes from the model, because which disk serves images is
     * configuration and a browser cannot read config. The keys are the ones the
     * form posts back, so a row survives a round trip unchanged.
     *
     * @return list<array{id: int, path: string, alt_text: string|null, url: string}>
     */
    public function gallery(Product $product): array
    {
        return $product->images
            ->map(fn (ProductImage $image): array => [
                'id' => $image->id,
                'path' => $image->path,
                'alt_text' => $image->alt_text,
                'url' => $image->url,
            ])
            ->values()
            ->all();
    }
}
