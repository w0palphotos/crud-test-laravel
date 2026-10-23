<?php

namespace App\Actions;

use DOMDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Removes the files a sale note no longer references.
 *
 * Files are uploaded once and stored as `src` URLs (images) or `href` URLs
 * (links) inside the note's HTML. When an amendment takes a file out of the
 * note, nothing else points at it, so it would sit in storage for ever. This
 * reconciles the files the note used to reference with the ones it references
 * now, and deletes the files that fell out of the note.
 *
 * Only files that live on the note's own image disk are deleted: an external
 * link or a URL from another disk is left alone, because nothing here owns it.
 *
 * The deletion is scheduled after the transaction commits: if the amendment is
 * rolled back, nothing the note still references is lost.
 */
class SyncSaleNoteFiles
{
    /**
     * @param  string|null  $previous  The note as it was stored.
     * @param  string|null  $current  The note as it is being stored.
     */
    public function handle(?string $previous, ?string $current): void
    {
        $removed = array_values(array_diff(
            $this->fileUrls($previous),
            $this->fileUrls($current),
        ));

        if ($removed === []) {
            return;
        }

        DB::afterCommit(function () use ($removed): void {
            $disk = Storage::disk(config('images.disk'));

            foreach ($removed as $url) {
                $path = $this->imagePathFromUrl($url);

                if ($path !== null && $disk->exists($path)) {
                    $disk->delete($path);
                }
            }
        });
    }

    /**
     * @return list<string>
     */
    private function fileUrls(?string $html): array
    {
        if ($html === null || trim($html) === '') {
            return [];
        }

        $dom = new DOMDocument;

        if (@$dom->loadHTML('<?xml encoding="utf-8" ?><div>'.$html.'</div>') === false) {
            return [];
        }

        $urls = [];

        foreach (['img' => 'src', 'a' => 'href'] as $tag => $attribute) {
            foreach ($dom->getElementsByTagName($tag) as $node) {
                if (! $node->hasAttribute($attribute)) {
                    continue;
                }

                $urls[] = $node->getAttribute($attribute);
            }
        }

        return array_values(array_unique($urls));
    }

    private function imagePathFromUrl(string $url): ?string
    {
        $disk = config('filesystems.disks.'.config('images.disk') ?? []);

        // public/local disk: /storage/uploads/abc.jpg → uploads/abc.jpg
        $diskUrl = $disk['url'] ?? null;

        if (is_string($diskUrl) && str_starts_with($url, $diskUrl)) {
            return ltrim(substr($url, strlen($diskUrl)), '/');
        }

        // An external URL or another disk's URL: nothing here owns it.
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return null;
        }

        return $url;
    }
}
