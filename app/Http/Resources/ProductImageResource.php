<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * One row of a product's gallery, as both the API reads it and a caller sends it.
 *
 * Two of the five fields are readOnly because a caller identifies an existing row
 * by `id` rather than naming its file, and writes a new one by `path`. That is the
 * pair SyncProductImages tells apart: an id is a row this product already owns, a
 * path is a file the upload endpoint has just accepted.
 */
#[OA\Schema(
    schema: 'ProductImage',
    properties: [
        'id' => new OA\Property(property: 'id', type: 'integer', readOnly: true, nullable: true, description: 'Set when referring to a row the product already has.'),
        'path' => new OA\Property(property: 'path', type: 'string', readOnly: true, nullable: true, description: 'Set for a newly uploaded file. Must already exist on the image disk.', example: 'uploads/8f14e45f-ceea-467a-9f8e-1a2f3c4d5e6f.jpg'),
        'alt_text' => new OA\Property(property: 'alt_text', type: 'string', maxLength: 255, nullable: true, description: 'What the picture shows, for anyone using a screen reader. A blank is stored as null.'),
        'sort_order' => new OA\Property(property: 'sort_order', type: 'integer', readOnly: true, description: 'Position in the gallery. Read-only on the way in: the submitted list\'s order is what becomes the order.'),
        'url' => new OA\Property(property: 'url', type: 'string', format: 'uri', readOnly: true, description: 'Where the image can be fetched.'),
    ],
)]
class ProductImageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'path' => $this->resource->path,
            'alt_text' => $this->resource->alt_text,
            'sort_order' => $this->resource->sort_order,
            'url' => $this->resource->url,
        ];
    }
}
