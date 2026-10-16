<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * Also the `Product` component schema: the same definition backs both the
 * request bodies and the responses, so `readOnly` fields (id, timestamps) are
 * hidden by Swagger UI when composing a create or update body.
 *
 * The gallery is here because the API accepts it on the way in — StoreProductRequest
 * validates `images` for every caller — so a client could post pictures that were
 * then dropped without a word. Anything a request may send has to come back out,
 * or the response is describing a different product than the one that was written.
 */
#[OA\Schema(
    schema: 'Product',
    required: ['id', 'name', 'price', 'stock', 'created_at', 'updated_at'],
    properties: [
        'id' => new OA\Property(property: 'id', type: 'integer', readOnly: true, example: 1),
        'name' => new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Mechanical Keyboard'),
        'description' => new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Hot-swappable 75% board.'),
        'price' => new OA\Property(property: 'price', type: 'number', format: 'float', minimum: 0, example: 129.99),
        'stock' => new OA\Property(property: 'stock', type: 'integer', minimum: 0, example: 42),
        'images' => new OA\Property(
            property: 'images',
            type: 'array',
            description: 'The gallery, in gallery order. The first row is the one the catalogue shows as the product\'s picture.',
            items: new OA\Items(ref: '#/components/schemas/ProductImage'),
        ),
        'created_at' => new OA\Property(property: 'created_at', type: 'string', format: 'date-time', readOnly: true),
        'updated_at' => new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', readOnly: true),
    ],
)]
class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),

            // Named rather than spread, because a relation called images would
            // otherwise be serialised as a raw collection of models — every column,
            // and a `product_id` on each one that says what the envelope already
            // said.
            //
            // Defaulted rather than assumed: show() may be handed a product loaded
            // without its gallery, and an absent key would be read as "no images"
            // when it means "not loaded".
            //
            // Resolved to plain arrays rather than nested resources, because these
            // are not links to anything — there is no route that returns one image.
            'images' => $this->whenLoaded(
                'images',
                fn (): array => ProductImageResource::collection($this->images)->resolve(),
            ),
        ];
    }
}
