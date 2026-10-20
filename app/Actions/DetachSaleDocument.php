<?php

namespace App\Actions;

use App\Models\SaleDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Takes a document off a sale.
 *
 * The row goes first and the file second, the opposite of attaching. A row for a
 * file that is still there is a document the desk can open and read; a file with
 * no row is a file nobody can find, and the second is recoverable by deleting
 * it. So the row goes first, and a file that cannot be deleted is left behind
 * rather than failing the whole removal — a file nobody can reach is a nuisance,
 * a document that is still listed and cannot be opened is a broken desk.
 */
class DetachSaleDocument
{
    public function handle(SaleDocument $document): void
    {
        $path = $document->path;

        DB::transaction(fn () => $document->delete());

        try {
            Storage::disk(config('documents.disk'))->delete($path);
        } catch (\Throwable $e) {
            // Reported, not raised: the document is off the sale, which is what
            // was asked for. The file is unreachable rather than mislaid — nothing
            // refers to it any more.
            report($e);
        }
    }
}
