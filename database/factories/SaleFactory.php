<?php

namespace Database\Factories;

use App\Enums\SaleStatus;
use App\Models\City;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\Salesperson;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $grandTotal = fake()->randomFloat(2, 5_000_000, 500_000_000);

        return [
            'reference' => 'SLV-'.Str::upper(Str::random(10)),
            'customer_id' => Customer::factory(),
            'salesperson_id' => Salesperson::factory(),
            'city_id' => City::factory(),
            'status' => SaleStatus::Pending,
            'sold_at' => fake()->dateTimeBetween('-14 months', 'now'),
            // tax_total stays zero so subtotal and grand_total are the same
            // figure, which keeps a test's arithmetic honest without every one
            // of them having to reason about tax.
            'subtotal' => $grandTotal,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => $grandTotal,
        ];
    }

    /**
     * A sale already delivered, for tests that care about settled history.
     */
    public function delivered(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SaleStatus::Delivered,
        ]);
    }
}
