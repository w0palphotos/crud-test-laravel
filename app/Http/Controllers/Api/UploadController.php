<?php

namespace App\Http\Controllers\Api;

use App\Actions\StoreUploadedImage;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUploadRequest;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * The API's own image upload.
 *
 * It exists because a product is required to have a gallery, and the gallery is
 * described by paths into the image disk. Without a way to put a file there, an
 * API client could not satisfy that rule at all and the create endpoint would be
 * unusable — a rule that cannot be met is not a rule, it is a closed door.
 *
 * Same action as the browser's endpoint, same limits, same throttle. What differs
 * is the credential in front of it and the shape of the reply, and neither of those
 * is worth a second implementation.
 */
#[OA\Tag(name: 'Uploads', description: 'Store an image and return the path a product gallery refers to.')]
class UploadController extends Controller
{
    #[OA\Post(
        path: '/api/uploads',
        summary: 'Upload an image',
        description: 'Stores one image on the image disk and returns the `path` to send as `images[].path` when creating or amending a product. Accepts the same formats and the same size cap as the browser upload.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                required: ['image'],
                properties: [
                    'image' => new OA\Property(property: 'image', type: 'string', format: 'binary'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'The stored image.', content: new OA\JsonContent(type: 'object', properties: [
                'path' => new OA\Property(property: 'path', type: 'string', example: 'uploads/8f14e45f-ceea-467a-9f8e-1a2f3c4d5e6f.jpg'),
                'url' => new OA\Property(property: 'url', type: 'string', example: 'https://example.test/storage/uploads/8f14e45f-ceea-467a-9f8e-1a2f3c4d5e6f.jpg'),
                'name' => new OA\Property(property: 'name', type: 'string', example: '8f14e45f-ceea-467a-9f8e-1a2f3c4d5e6f.jpg'),
            ])),
            new OA\Response(response: 401, description: 'Unauthenticated.'),
            new OA\Response(response: 403, description: 'Not an administrator.'),
            new OA\Response(response: 422, description: 'Not an accepted image, or too large.'),
            new OA\Response(response: 429, description: 'Too many uploads.'),
        ],
    )]
    public function store(StoreUploadRequest $request, StoreUploadedImage $store): JsonResponse
    {
        // 201 rather than the browser endpoint's 200, because this is the API and
        // every other creation on it answers 201. The browser's JavaScript checks
        // `response.ok`, which covers both, so its endpoint is left as it was.
        return response()->json($store->handle($request->file('image')), 201);
    }
}
