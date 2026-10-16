<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * Also the `Customer` component schema.
 *
 * The profile is a nested object rather than a flat handful of columns, because it
 * is a separate row with a lifecycle of its own: a customer exists from the first
 * order and the profile is written when the desk onboards them. Flattening it would
 * hide whether `credit_limit` is null because the customer is un-onboarded or
 * because the value was never set — the same distinction Customer::profile's
 * comment makes about not defaulting the relation.
 *
 * Omitted entirely when it has not been loaded, rather than sent as null. An
 * absent `profile` means "this response did not ask"; a null one would claim the
 * customer has no profile, which is a statement about the customer.
 */
#[OA\Schema(
    schema: 'Customer',
    required: ['id', 'name', 'email', 'created_at', 'updated_at'],
    properties: [
        'id' => new OA\Property(property: 'id', type: 'integer', readOnly: true, example: 1),
        'name' => new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Budi Santoso'),
        'email' => new OA\Property(property: 'email', type: 'string', format: 'email', maxLength: 255, example: 'budi@example.com'),
        'phone' => new OA\Property(property: 'phone', type: 'string', maxLength: 24, nullable: true, example: '08123456789'),
        'profile' => new OA\Property(property: 'profile', type: 'object', nullable: true, description: 'The commercial profile. Absent when the response did not include it; null when the customer has not been onboarded.', ref: '#/components/schemas/CustomerProfile'),
        'created_at' => new OA\Property(property: 'created_at', type: 'string', format: 'date-time', readOnly: true),
        'updated_at' => new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', readOnly: true),
    ],
)]
class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,

            // Not `whenLoaded`: a customer with no profile is `null` here and a
            // customer whose profile was not loaded has no `profile` key at all,
            // and the two are different answers.
            'profile' => $this->whenLoaded('profile', fn () => $this->profile === null
                ? null
                : new CustomerProfileResource($this->profile)),
        ];
    }
}
