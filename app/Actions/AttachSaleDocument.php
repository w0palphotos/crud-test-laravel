<?php

namespace App\Actions;

use App\Models\Sale;
use App\Models\SaleDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Files a document with a sale.
 *
 * The file is written first and the row second, and the two are in one
 * transaction — but a file on a disk is not in the transaction, so the order
 * matters. A row for a file that was never written is a document nobody can open;
 * a file with no row is a file nobody can find, and the second is recoverable by
 * deleting it. So the file goes first, and a row that cannot be made is a failure
 * that also removes the file.
 */
class AttachSaleDocument
{
    /**
     * @return array{path: string, url: string, name: string}
     */
    public function handle(Sale $sale, UploadedFile $file, ?User $actor = null): array
    {
        $extension = $file->guessExtension();

        // The stored name is generated, never taken from the client: a path
        // assembled from input is how an upload escapes its directory, and a
        // predictable name lets one person's file be overwritten by another's guess.
        $name = Str::uuid().($extension === null ? '' : ".{$extension}");

        $stored = Storage::disk(config('documents.disk'))->putFileAs(
            config('documents.directory'),
            $file,
            $name,
        );

        if ($stored === false) {
            throw new RuntimeException('Dokumen gagal disimpan. Periksa konfigurasi Storage.');
        }

        try {
            $document = DB::transaction(fn (): SaleDocument => $sale->documents()->create([
                // The path the storage reported, not one assembled here: the two
                // must not be able to drift apart, and the row has to name the
                // file that is really there.
                'path' => $stored,
                'original_name' => $this->originalName($file),
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'size' => $file->getSize() ?: 0,
                'uploaded_by' => $actor?->id,
            ]));
        } catch (\Throwable $e) {
            // The file is already written, and a file with no row is a file
            // nobody can find. It goes back.
            Storage::disk(config('documents.disk'))->delete($stored);

            throw $e;
        }

        return [
            'path' => $document->path,
            'url' => $document->temporaryUrl((int) config('documents.signed_url_minutes')),
            'name' => $document->original_name,
        ];
    }

    /**
     * The name the person gave the file.
     *
     * Kept, because it is the only name they will recognise — "surat-jalan-042.pdf"
     * is a document and "9f2c1a….pdf" is a file nobody filed. The path is
     * stripped from it because some browsers send one, and a name is a name.
     */
    private function originalName(UploadedFile $file): string
    {
        $name = basename((string) $file->getClientOriginalName());

        if ($name === '' || $name === '.' || $name === '/') {
            return 'dokumen';
        }

        return mb_substr($name, 0, 200);
    }
}
