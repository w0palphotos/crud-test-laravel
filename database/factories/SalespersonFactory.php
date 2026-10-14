<?php

namespace Database\Factories;

use App\Models\Salesperson;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Salesperson>
 */
class SalespersonFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->optional()->phoneNumber(),
            'commission_rate' => fake()->randomFloat(2, 1, 8),
        ];
    }
}
