<?php

namespace Database\Factories;

use App\Models\City;
use App\Models\Customer;
use App\Models\CustomerProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerProfile>
 */
class CustomerProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // The customer comes from the factory rather than being generated,
            // so a profile always belongs to a row that exists. Without this a
            // test could build a profile for nobody and only find out at the
            // foreign key.
            'customer_id' => Customer::factory(),
            'city_id' => City::factory(),
            'address' => fake()->streetAddress(),
            'tax_id' => fake()->optional()->numerify('09.###.###.#-###.###'),
            'credit_limit' => fake()->randomFloat(2, 0, 500_000_000),
            'payment_terms' => fake()->randomElement(['COD', 'Net 14', 'Net 30', 'Transfer']),
        ];
    }
}
