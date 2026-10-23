<?php

namespace Database\Factories;

use App\Models\Sale;
use App\Models\SaleImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleImage>
 */
class SaleImageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sale_id' => Sale::factory(),
            // A path rather than a real upload. Storage is exercised by the upload
            // endpoint's own tests; seeding a fake URL here would mean a seeded
            // sale full of broken images.
            'path' => 'uploads/'.fake()->uuid().'.jpg',
            'sort_order' => fake()->numberBetween(0, 10),
        ];
    }
}
