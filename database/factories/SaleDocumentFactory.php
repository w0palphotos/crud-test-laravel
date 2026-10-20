<?php

namespace Database\Factories;

use App\Models\Sale;
use App\Models\SaleDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleDocument>
 */
class SaleDocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sale_id' => Sale::factory(),
            'path' => 'sale-documents/'.fake()->uuid().'.pdf',
            'original_name' => fake()->words(2, true).'.pdf',
            'mime_type' => 'application/pdf',
            'size' => fake()->numberBetween(10_000, 900_000),
            'uploaded_by' => null,
        ];
    }

    /**
     * A document that is a plain text file, for the tests that care about the
     * type rather than the name.
     */
    public function text(): static
    {
        return $this->state(fn (array $attributes): array => [
            'path' => 'sale-documents/'.fake()->uuid().'.txt',
            'original_name' => fake()->words(2, true).'.txt',
            'mime_type' => 'text/plain',
        ]);
    }
}
