<?php

namespace App\Models;

use Database\Factories\SaleDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A document filed with a sale.
 *
 * The file lives on the documents disk, in a private bucket, and only the path to
 * it is stored. A url would have been easier to keep and worse to have: a signed
 * url expires, and one written into a column would stop working on its own with
 * nothing to say why.
 */
#[Fillable(['sale_id', 'path', 'original_name', 'mime_type', 'size', 'uploaded_by'])]
class SaleDocument extends Model
{
    /** @use HasFactory<SaleDocumentFactory> */
    use HasFactory;

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * Who filed it. Null when that person has left, and the document stays.
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * A link that works for a while.
     *
     * Signed, because the bucket is private and the document is not the public's
     * business. The file is offered under the name the person gave it, so what
     * arrives at the other end is "surat-jalan-042.pdf" rather than the generated
     * name it is stored under.
     *
     * A disk that cannot sign anything falls back to its own address, which is
     * what makes the feature usable on a desk running on somebody's own machine:
     * the local disk serves what it stores and signs nothing. A disk that can sign
     * but failed to is a fault, and it is reported rather than papered over.
     */
    public function temporaryUrl(int $minutes): string
    {
        $disk = Storage::disk(config('documents.disk'));

        if (! $disk->providesTemporaryUrls()) {
            return $disk->url($this->path);
        }

        try {
            return $disk->temporaryUrl(
                $this->path,
                now()->addMinutes($minutes),
                [
                    'ResponseContentDisposition' => 'attachment; filename="'.$this->downloadName().'"',
                ],
            );
        } catch (\Throwable $e) {
            report($e);

            return $disk->url($this->path);
        }
    }

    /**
     * The name the file is offered under.
     *
     * The stored name is generated, and a generated name in a download prompt is
     * a name nobody recognises. Quotes and newlines are stripped because the
     * name goes into a header, and a header is not a place for a newline.
     */
    public function downloadName(): string
    {
        $name = str_replace(['"', "\r", "\n"], '', $this->original_name);

        return $name === '' ? 'dokumen' : $name;
    }

    /**
     * The size, as a person reads it.
     */
    public function readableSize(): string
    {
        $bytes = $this->size;

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / (1024 * 1024), 1).' MB';
    }
}
