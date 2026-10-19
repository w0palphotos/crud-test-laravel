<?php

namespace App\Http\Controllers\Web;

use App\Actions\StoreUploadedImage;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUploadRequest;
use Illuminate\Http\JsonResponse;

/**
 * Stores an uploaded image and hands back its URL.
 *
 * The endpoint is called by the rich text editor and the image picker rather than
 * by a form post, so it answers JSON. It is a web route under the session guard:
 * the caller is a signed-in member of staff, which is the whole authorisation
 * story. There is no separate token scheme for uploads.
 *
 * The work itself is in StoreUploadedImage, which the API's own upload endpoint
 * calls too.
 */
class UploadController extends Controller
{
    public function store(StoreUploadRequest $request, StoreUploadedImage $store): JsonResponse
    {
        return response()->json($store->handle($request->file('image')));
    }
}
