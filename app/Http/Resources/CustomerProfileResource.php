<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * Also the `CustomerProfile` component schema: the five columns of
 * `customer_profiles`.
 *
 * `city` is a nested object rather than an id, for the same reason the ledger
 * sends a tag's colour alongside its name — a consumer of this API has no list of
 * cities to resolve an id against, and an id it cannot resolve is an id it has to
 * guess about. Omitted when it has not been loaded.
 */
#[OA\Schema(
    schema: 'CustomerProfile',
    properties: [
        'city_id' => new OA\Property(property: 'city_id', type: 'integer', nullable: true, example: 1),
        'address' => new OA\Property(property: 'address', type: 'string', nullable: true, example: 'Jl. Merdeka 10'),
        'tax_id' => new OA\Property(property: 'tax_id', type: 'string', maxLength: 32, nullable: true, example: '01.234.567.8-901.000'),
        'credit_limit' => new OA\Property(property: 'credit_limit', type: 'number', format: 'float', minimum: 0, example: 25000000),
        'payment_terms' => new OA\Property(property: 'payment_terms', type: 'string', maxLength: 64, example: 'Net 30'),
        'city' => new OA\Property(
            property: 'city',
            type: 'object',
            nullable: true,
            description: 'The city, inlined. Not a $ref to a City schema because this API has no city endpoints and no other resource needs one — a shared schema with a single caller is a second place to change for one line.',
            properties: [
                'id' => new OA\Property(property: 'id', type: 'integer'),
                'name' => new OA\Property(property: 'name', type: 'string'),
                'province' => new OA\Property(property: 'province', type: 'string', nullable: true),
            ],
        ),
        'created_at' => new OA\Property(property: 'created_at', type: 'string', format: 'date-time', readOnly: true),
        'updated_at' => new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', readOnly: true),
    ],
)]
class CustomerProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'city_id' => $this->city_id,
            'address' => $this->address,
            'tax_id' => $this->tax_id,
            'credit_limit' => (float) $this->credit_limit,
            'payment_terms' => $this->payment_terms,
            'city' => $this->whenLoaded('city', fn () => $this->city === null ? null : [
                'id' => $this->city->id,
                'name' => $this->city->name,
                'province' => $this->city->province,
            ]),
        ];
    }
}
