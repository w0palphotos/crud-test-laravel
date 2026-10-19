<?php

namespace App\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Puts an uploaded file on the image disk and says where it landed.
 *
 * Shared by the browser's upload endpoint and the API's, because the two differ in
 * exactly two things — how the caller proves who they are, and what the reply is
 * wrapped in — and not in how a file is named, where it goes, or what the caller is
 * told afterwards. Two copies would be two places for a naming rule to change in
 * one of them only, and the files left behind by the other would be the evidence.
 *
 * The endpoint is not the trust boundary and neither is this: what the file is, is
 * checked by StoreUploadRequest before it arrives here.
 */
class StoreUploadedImage
{
    /**
     * @return array{path: string, url: string, name: string}
     */
    public function handle(UploadedFile $file): array
    {
        // The stored name is generated, never taken from the client.
        //
        // A client-supplied filename is an attack surface with three separate
        // edges: `../../config/app.php` writes outside the directory, `image.php`
        // becomes executable wherever the server serves PHP, and a predictable
        // name lets one user's upload overwrite another's by guessing it. A UUID
        // has none of those, and it removes the "is this filename already taken"
        // question entirely.
        $extension = $file->guessExtension();

        $name = Str::uuid().($extension === null ? '' : ".{$extension}");

        $path = $file->storeAs(config('images.directory'), $name, config('images.disk'));

        return [
            'path' => $path,
            'url' => Storage::disk(config('images.disk'))->url($path),
            'name' => $name,
        ];
    }
}
