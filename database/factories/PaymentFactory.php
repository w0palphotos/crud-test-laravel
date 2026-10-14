<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sale_id' => Sale::factory(),
            'method' => fake()->randomElement(['transfer', 'cash', 'credit_card', 'cod']),
            'status' => fake()->randomElement(['unpaid', 'partial', 'paid', 'refunded']),
            'amount' => fake()->randomFloat(2, 5_000_000, 500_000_000),
            'paid_at' => fake()->optional()->dateTimeBetween('-14 months', 'now'),
            'reference' => fake()->optional()->bothify('TRX-########'),
        ];
    }
}
