<?php

namespace App\Actions;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Writes a document to the documents disk and says where it landed.
 *
 * The file is written without a sale to hang it on, because a sale may not exist
 * yet: the create form files its documents before the sale is made, the same way
 * the product form files its gallery. What comes back is a description of the
 * file, and the sale is given that description later.
 *
 * The path is what is kept, never a url. A url of a private object is signed and
 * expires, so a url written into a column would stop working on its own, with
 * nothing to say why.
 */
class StoreUploadedDocument
{
    /**
     * @return array{path: string, original_name: string, mime_type: string, size: int}
     */
    public function handle(UploadedFile $file): array
    {
        $disk = $this->disk();

        // The stored name is generated, never taken from the client: a path
        // assembled from input is how an upload escapes its directory, and a
        // predictable name lets one person's file be overwritten by another's
        // guess. A UUID has neither problem.
        $extension = $file->guessExtension();

        $name = Str::uuid().($extension === null ? '' : ".{$extension}");

        $stored = $disk->putFileAs(
            config('documents.directory'),
            $file,
            $name,
        );

        if ($stored === false) {
            throw new RuntimeException('Dokumen gagal disimpan. Periksa konfigurasi Storage.');
        }

        return [
            'path' => $stored,
            'original_name' => $this->originalName($file),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size' => $file->getSize() ?: 0,
        ];
    }

    /**
     * The disk the documents go to, checked before it is built.
     *
     * A bucket that was never named is a configuration mistake, and the storage
     * client would fail on it with a type error from inside its own constructor —
     * which names a parameter of a library and says nothing about what to fix.
     */
    private function disk(): Filesystem
    {
        $name = config('documents.disk');
        $config = config("filesystems.disks.{$name}");

        if (! is_array($config)) {
            throw new RuntimeException("Disk dokumen '{$name}' tidak ada di konfigurasi.");
        }

        if (($config['driver'] ?? null) === 's3' && empty($config['bucket'])) {
            throw new RuntimeException(
                'Bucket dokumen belum diisi. Set AWS_BUCKET_DOCUMENTS di environment, lalu deploy ulang.'
            );
        }

        return Storage::disk($name);
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
