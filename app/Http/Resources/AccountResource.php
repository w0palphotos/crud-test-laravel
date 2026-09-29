<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * Also the `Account` component schema. Credentials are deliberately absent:
 * they are write-only inputs and must never appear in a response.
 */
#[OA\Schema(
    schema: 'Account',
    required: ['id', 'name', 'email', 'created_at', 'updated_at'],
    properties: [
        'id' => new OA\Property(property: 'id', type: 'integer', readOnly: true, example: 1),
        'name' => new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Test User'),
        'email' => new OA\Property(property: 'email', type: 'string', format: 'email', maxLength: 255, example: 'test@example.com'),
        'email_verified_at' => new OA\Property(property: 'email_verified_at', type: 'string', format: 'date-time', nullable: true, readOnly: true, description: 'Always null: this API never sends verification mail.'),
        'created_at' => new OA\Property(property: 'created_at', type: 'string', format: 'date-time', readOnly: true),
        'updated_at' => new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', readOnly: true),
    ],
)]
class AccountResource extends JsonResource
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
