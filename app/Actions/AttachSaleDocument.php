<?php

namespace App\Actions;

use App\Models\Sale;
use App\Models\SaleDocument;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Files a document with a sale.
 *
 * The file is already on the disk when this runs: it was written when it was
 * chosen, because a sale may not have existed yet. What arrives is a description
 * of the file, and the description is checked against the disk before it is
 * believed — a document that points at nothing is a document nobody can open.
 */
class AttachSaleDocument
{
    /**
     * @param  array{path: string, original_name: string, mime_type: string, size: int}  $document
     */
    public function handle(Sale $sale, array $document, ?User $actor = null): SaleDocument
    {
        if (! Storage::disk(config('documents.disk'))->exists($document['path'])) {
            throw new RuntimeException('Berkas dokumen tidak ditemukan di storage.');
        }

        return $sale->documents()->create([
            'path' => $document['path'],
            'original_name' => $document['original_name'],
            'mime_type' => $document['mime_type'],
            'size' => $document['size'],
            'uploaded_by' => $actor?->id,
        ]);
    }
}
