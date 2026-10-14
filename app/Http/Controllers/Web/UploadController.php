<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUploadRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores an uploaded image and hands back its URL.
 *
 * The endpoint is called by the rich text editor and the image picker rather than
 * by a form post, so it answers JSON. It is a web route under the session guard:
 * the caller is a signed-in member of staff, which is the whole authorisation
 * story. There is no separate token scheme for uploads.
 */
class UploadController extends Controller
{
    public function store(StoreUploadRequest $request): JsonResponse
    {
        $file = $request->file('image');

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

        return response()->json([
            'path' => $path,
            'url' => Storage::disk(config('images.disk'))->url($path),
            'name' => $name,
        ]);
    }
}
