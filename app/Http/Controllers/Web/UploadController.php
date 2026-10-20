<?php

namespace App\Http\Controllers\Web;

use App\Actions\StoreUploadedImage;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUploadRequest;
use Illuminate\Http\JsonResponse;
use RuntimeException;

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
    /**
     * The failure is caught here rather than left to the exception handler.
     *
     * The handler would answer "Server Error" — APP_DEBUG is off in production,
     * so the message never reaches the browser. But the reason an upload failed is
     * a sentence about a bucket, a key or a region, and the person who can fix it
     * is the one watching the editor. The editor already shows the message it is
     * given, so the sentence lands where they are looking.
     *
     * What is sent is the explanation and not the raw error: the raw one names
     * the endpoint and the bucket, and this is a reply to a browser.
     */
    public function store(StoreUploadRequest $request, StoreUploadedImage $store): JsonResponse
    {
        try {
            return response()->json($store->handle($request->file('image')));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
