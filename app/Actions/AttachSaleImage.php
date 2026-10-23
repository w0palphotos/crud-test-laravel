<?php

namespace App\Actions;

use App\Models\Sale;
use App\Models\SaleImage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Files an image with a sale.
 *
 * The file is already on the disk when this runs: it was written when it was
 * chosen. What arrives is a description of the file, and the description is
 * checked against the disk before it is believed — an image that points at
 * nothing is an image nobody can see.
 */
class AttachSaleImage
{
    /**
     * @param  array{path: string, url: string, name: string}  $file
     */
    public function handle(Sale $sale, array $file, int $sortOrder): SaleImage
    {
        if (! Storage::disk(config('images.disk'))->exists($file['path'])) {
            throw new RuntimeException('Gambar tidak ditemukan di storage.');
        }

        return $sale->images()->create([
            'path' => $file['path'],
            'sort_order' => $sortOrder,
        ]);
    }
}
