<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => Str::title($name),
            // Derived from the name so a test that creates a category twice with
            // the same name does not fail on the unique slug for a reason that
            // has nothing to do with what it is testing.
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
        ];
    }
}
