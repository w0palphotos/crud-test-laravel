<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * Also the `Product` component schema: the same definition backs both the
 * request bodies and the responses, so `readOnly` fields (id, timestamps) are
 * hidden by Swagger UI when composing a create or update body.
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
        return parent::toArray($request);
    }
}
