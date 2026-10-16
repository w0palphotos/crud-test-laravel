<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * Also the `Supplier` component schema.
 *
 * `products_count` is here because the supplier table shows it, and a count the
 * web page can show but the API cannot is a question every consumer has to answer
 * by writing a paginated query of its own.
 */
#[OA\Schema(
    schema: 'Supplier',
    required: ['id', 'name', 'created_at', 'updated_at'],
    properties: [
        'id' => new OA\Property(property: 'id', type: 'integer', readOnly: true, example: 1),
        'name' => new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'PT Sinar Abadi'),
        'contact_email' => new OA\Property(property: 'contact_email', type: 'string', format: 'email', maxLength: 255, nullable: true, example: 'sales@sinarabadi.co.id'),
        'phone' => new OA\Property(property: 'phone', type: 'string', maxLength: 24, nullable: true, example: '0215551234'),
        'products_count' => new OA\Property(property: 'products_count', type: 'integer', readOnly: true, description: 'Products this supplier is paired with.', example: 3),
        'created_at' => new OA\Property(property: 'created_at', type: 'string', format: 'date-time', readOnly: true),
        'updated_at' => new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', readOnly: true),
    ],
)]
class SupplierResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'contact_email' => $this->contact_email,
            'phone' => $this->phone,

            // Only when the query counted it. The list does; show() does not, and
            // a count that silently defaulted to zero would be a lie about a
            // supplier who supplies half the catalogue.
            'products_count' => $this->whenCounted('products'),
        ];
    }
}
