<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductImage>
 */
class ProductImageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            // A path rather than a real upload. Storage is exercised by the upload
            // endpoint's own tests; seeding a fake URL here would mean a seeded
            // catalogue full of broken images.
            'path' => 'products/'.fake()->uuid().'.jpg',
            'alt_text' => fake()->optional()->sentence(4),
            'sort_order' => fake()->numberBetween(0, 10),
        ];
    }
}
